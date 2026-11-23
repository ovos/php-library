<?php
declare(strict_types=1);

namespace Tests\Service\Codesafe;

use APCUIterator;
use ErrorException;
use Exception;
use Ovos\Application;
use Ovos\ArrayObject;
use Ovos\Cache\Prefixer;
use Ovos\Container;
use Ovos\Controller;
use Ovos\Controller\Plugin;
use Ovos\Exception\ForbiddenException;
use Ovos\Exception\NotFoundException;
use Ovos\Exception\NotFoundException\FileNotFoundException;
use Ovos\Exception\Priority;
use Ovos\Request;
use Ovos\Service\Codesafe\Otlp;
use Ovos\Service\Codesafe\Rollup as CodesafeRollup;
use Ovos\Service\Codesafe\Sender as CodesafeSender;
use Ovos\Service\Codesafe\Untracked;
use Ovos\Service\Events;
use Ovos\Service\Logger;
use Ovos\Services;
use Ovos\Test;
use Ovos\Test\Exception\SkipException;
use RuntimeException;
use Throwable;
use WeakReference;

use function apcu_delete;
use function apcu_enabled;
use function array_column;
use function array_filter;
use function array_key_exists;
use function array_keys;
use function array_push;
use function array_slice;
use function bin2hex;
use function count;
use function function_exists;
use function gc_collect_cycles;
use function in_array;
use function json_decode;
use function json_encode;
use function mb_strlen;
use function Ovos\container;
use function preg_match;
use function preg_quote;
use function random_bytes;
use function str_contains;
use function str_repeat;
use function str_starts_with;

use const E_WARNING;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;

/**
 * Sender — capture-side queue semantics (no HTTP: the test double
 * neuters send(); flush() is never invoked, so no shutdown coupling).
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Sender extends Test
{
	/**
	 * The release announce (SENDER.md §7): the body is the label the events
	 * carry — its first line, capped like the column — the source (this
	 * library when the deploy step names none) and the optional fields only
	 * when given; and nothing at all without a label, so a deploy step on an
	 * unstamped checkout announces nothing rather than an empty release
	 */
	public function releasePayloadIsTheAnnounceBodyOrNothing(): bool
	{
		$plain = CodesafeSender::releasePayload('42800', [], 'production');
		$full = CodesafeSender::releasePayload("r42800\nnoise", [
			'at' => 1788700000, 'ref' => ' r42800 ', 'source' => 'deploy.sh', 'environment' => 'staging'], 'production');
		$long = CodesafeSender::releasePayload(str_repeat('a', 80), [
			'ref' => str_repeat('b', 200), 'source' => str_repeat('c', 40)], '');
		
		return $plain === ['release' => '42800', 'source' => 'php-library', 'environment' => 'production']
			&& $full === ['release' => 'r42800', 'source' => 'deploy.sh', 'at' => 1788700000, 'ref' => 'r42800', 'environment' => 'staging']
			&& mb_strlen($long['release']) === 64
			&& mb_strlen($long['ref']) === 128
			&& mb_strlen($long['source']) === 32
			&& isset($long['environment']) === false
			&& CodesafeSender::releasePayload('', ['ref' => 'x'], 'production') === []
			&& CodesafeSender::releasePayload("  \n", [], 'production') === []
			// an ISO moment travels as given, a blank one not at all
			&& CodesafeSender::releasePayload('1.0', ['at' => ' 2026-09-07T10:00:00+02:00 '], '')['at'] === '2026-09-07T10:00:00+02:00'
			&& isset(CodesafeSender::releasePayload('1.0', ['at' => ''], '')['at']) === false;
	}
	
	/**
	 * The hello (codesafe SENDER.md §7, docs/plans/project-features-live.md):
	 * the switches this library has, in codesafe's words — security left out,
	 * since the app calling reportRefusal() is its only opt-in — the Shield's
	 * two only where the kernel runs, both on and the inventory on unless set
	 * to no (codesafe docs/plans/shield-auto-deploy.md D8, P0), and nothing at
	 * all from a sender that is off
	 */
	public function helloPayloadNamesOnlyTheSwitchesThisLibraryHas(): bool
	{
		$on = CodesafeSender::helloPayload(new ArrayObject([
			'enabled' => true,
			'report_404' => true,
			'rollups' => false,
			'files' => ['web' => ['public']],
			'shield' => ['detect' => true, 'enforce' => true, 'kill' => false],
		]), 'php-library/8.5.41');
		$killed = CodesafeSender::helloPayload(new ArrayObject([
			'enabled' => true,
			'shield' => ['detect' => true, 'enforce' => true, 'kill' => true],
		]), 'php-library/dev');
		$unset = CodesafeSender::helloPayload(new ArrayObject(['enabled' => true]), 'php-library/dev');
		$declined = CodesafeSender::helloPayload(new ArrayObject([
			'enabled' => true,
			'inventory' => false,
			'shield' => ['enforce' => false],
		]), 'php-library/dev');
		
		return $on === [
				'v' => 1,
				'client' => 'php-library/8.5.41',
				'features' => [
					'errors' => true,
					'not_found' => true,
					'rollups' => false,
					'files' => true,
					'inventory' => true,
					'shield_detect' => true,
					'shield_enforce' => true,
				],
			]
			&& array_key_exists('security', $on['features']) === false
			&& $killed['features']['shield_detect'] === false
			&& $killed['features']['shield_enforce'] === false
			&& $killed['features']['files'] === false
			&& $unset['features']['shield_detect'] && $unset['features']['shield_enforce'] && $unset['features']['inventory']
			&& $declined['features']['shield_detect'] && $declined['features']['shield_enforce'] === false
			&& $declined['features']['inventory'] === false
			&& CodesafeSender::helloPayload(new ArrayObject(['enabled' => false]), 'php-library/dev') === []
			&& CodesafeSender::helloPayload(null, 'php-library/dev') === [];
	}
	
	/**
	 * The hello is said when the switches' signature is not the one last said:
	 * once for a state, again for a change — and again for a change BACK within
	 * the day, which a key per signature missed (the first signature was still
	 * claimed, so codesafe kept the middle state for up to a day). A refused
	 * hello waits out its back-off rather than retrying every request.
	 */
	public function helloIsSaidOncePerStateAndAgainOnEveryChange(): bool
	{
		if(function_exists('apcu_enabled') === false || apcu_enabled() === false)
		{
			throw new SkipException('APCu is not enabled on this CLI');
		}
		
		$told = (new Prefixer())->prefix(CodesafeSender::HELLO_PREFIX . 'told');
		$lock = (new Prefixer())->prefix(CodesafeSender::HELLO_PREFIX . 'lock');
		apcu_delete([$told, $lock]);
		
		try
		{
			$posted = [];
			$say = function(bool $rollups, int $code = 202) use (&$posted): void
			{
				$sender = new class(new ArrayObject([
					'enabled' => true,
					'url' => 'https://console.invalid',
					'key' => 'test-key',
					'rollups' => $rollups,
				])) extends CodesafeSender
				{
					public int $code = 202;
					
					public array $posted = [];
					
					public function sayHello(): void
					{
						$this->maybeHello();
					}
					
					protected function post(
						string $path,
						string $json,
					): int
					{
						$this->posted[] = json_decode($json, true)['features']['rollups'];
						
						return $this->code;
					}
				};
				$sender->code = $code;
				$sender->sayHello();
				array_push($posted, ...$sender->posted);
			};
			
			$say(false);
			$say(false);
			$say(true);
			// the change back — the case a key per signature never said
			$say(false);
			$afterFlips = $posted;
			
			$say(true, 503);
			$say(true, 503);
			
			return $afterFlips === [false, true, false]
				&& $posted === [false, true, false, true];
		}
		finally
		{
			apcu_delete([$told, $lock]);
		}
	}
	
	/**
	 * RULE: the inventory (codesafe API.V1.md "Inventory", platform composer)
	 * is each installed package once, sorted, under its vendor/name with the
	 * name's tail as slug — and leaves out what the site does not run or
	 * codesafe would refuse: the app itself, a development-only requirement, a
	 * name another package replaces or provides, a name outside codesafe's
	 * pattern. A branch is sent versionless. Falsify: send `dev-main` as the
	 * version — codesafe reads a branch as older than every release and the
	 * package matches every advisory it ever had.
	 */
	public function inventoryIsTheInstalledPackagesOnceWithoutBranchesOrTheApp(): bool
	{
		$report = CodesafeSender::inventoryPayload([
			[
				'root' => ['name' => 'acme/shop'],
				'versions' => [
					'acme/shop' => ['pretty_version' => 'dev-main', 'dev_requirement' => false],
					'symfony/cache' => ['pretty_version' => 'v6.4.9', 'dev_requirement' => false],
					'psr/cache' => ['pretty_version' => '3.0.0', 'dev_requirement' => false],
					'phpunit/phpunit' => ['pretty_version' => '11.0.0', 'dev_requirement' => true],
					'psr/log-implementation' => ['dev_requirement' => false, 'provided' => ['3.0']],
					'ovos/php-library' => ['pretty_version' => 'dev-release/8.5', 'dev_requirement' => false],
					'acme/tool' => ['pretty_version' => '2.1.x-dev', 'dev_requirement' => false],
					'acme/odd' => ['pretty_version' => '1.0.0+build.7', 'dev_requirement' => false],
					'Acme/Upper' => ['pretty_version' => '1.0.0', 'dev_requirement' => false],
				],
			],
			// a second vendor dir: its root left out too, a package already listed listed once
			[
				'root' => ['name' => 'acme/plugin'],
				'versions' => [
					'acme/plugin' => ['pretty_version' => '1.0.0', 'dev_requirement' => false],
					'psr/cache' => ['pretty_version' => '1.0.1', 'dev_requirement' => false],
					'guzzlehttp/guzzle' => ['pretty_version' => '7.9.2', 'dev_requirement' => false],
				],
			],
		], '8.3.9');
		$versions = array_column($report['items'], 'version', 'package');
		
		return $report['platform'] === 'composer' && $report['core'] === '' && $report['php'] === '8.3.9'
			&& array_column($report['items'], 'package') === ['acme/odd', 'acme/tool', 'guzzlehttp/guzzle', 'ovos/php-library', 'psr/cache', 'symfony/cache']
			&& array_column($report['items'], 'slug') === ['odd', 'tool', 'guzzle', 'php-library', 'cache', 'cache']
			&& $versions === ['acme/odd' => '', 'acme/tool' => '', 'guzzlehttp/guzzle' => '7.9.2',
				'ovos/php-library' => '', 'psr/cache' => '3.0.0', 'symfony/cache' => 'v6.4.9']
			&& $report['items'][5] === ['type' => 'package', 'slug' => 'cache', 'package' => 'symfony/cache',
				'version' => 'v6.4.9', 'name' => 'symfony/cache', 'active' => true]
			&& CodesafeSender::inventoryPayload([], '8.3.9')['items'] === [];
	}
	
	/**
	 * RULE: the inventory is said when the installed list's stamp is not the
	 * one last said — once for a list, again after a composer install — and
	 * not at all with `inventory: no`; codesafe saying no (403: the project's
	 * CVE switch off) stands like a said list, no answer is asked again only
	 * after its back-off. Falsify: build and post the list on every request
	 * — every page view of the app carries a POST.
	 */
	public function inventoryIsSaidOncePerInstalledListAndNotWhenSwitchedOff(): bool
	{
		if(function_exists('apcu_enabled') === false || apcu_enabled() === false)
		{
			throw new SkipException('APCu is not enabled on this CLI');
		}
		
		$told = (new Prefixer())->prefix(CodesafeSender::INVENTORY_PREFIX . 'told');
		$lock = (new Prefixer())->prefix(CodesafeSender::INVENTORY_PREFIX . 'lock');
		apcu_delete([$told, $lock]);
		
		try
		{
			$posted = [];
			$say = function(string $stamp, int $code = 202, bool $inventory = true) use (&$posted): void
			{
				$sender = new class(new ArrayObject([
					'enabled' => true,
					'url' => 'https://console.invalid',
					'key' => 'test-key',
					'inventory' => $inventory,
				])) extends CodesafeSender
				{
					public string $stamp = '';
					
					public int $code = 202;
					
					public array $posted = [];
					
					public function sayInventory(): void
					{
						$this->maybeInventory();
					}
					
					protected function installedStamp(): string
					{
						return $this->stamp;
					}
					
					protected function installed(): array
					{
						return [['root' => ['name' => 'acme/shop'], 'versions' => [
							'psr/log' => ['pretty_version' => $this->stamp, 'dev_requirement' => false],
						]]];
					}
					
					protected function post(
						string $path,
						string $json,
					): int
					{
						$this->posted[] = $path . ' ' . json_decode($json, true)['items'][0]['version'];
						
						return $this->code;
					}
				};
				$sender->stamp = $stamp;
				$sender->code = $code;
				$sender->sayInventory();
				array_push($posted, ...$sender->posted);
			};
			
			$say('1.0.0');
			$say('1.0.0');
			$say('1.1.0', 403);
			$say('1.1.0');
			$say('1.2.0', 0);
			$say('1.2.0');
			$say('1.3.0', 202, false);
			
			return $posted === ['/api/v1/ingest/inventory 1.0.0', '/api/v1/ingest/inventory 1.1.0', '/api/v1/ingest/inventory 1.2.0'];
		}
		finally
		{
			apcu_delete([$told, $lock]);
		}
	}
	
	/**
	 * RULE: shieldVersion() reads the `shield` an ingest answer carries and
	 * nothing else — '' for an answer without one, a version that is not a
	 * string, a body that is not JSON (codesafe docs/plans/shield-auto-deploy.md P7.d)
	 */
	public function shieldVersionIsReadOffAnAnswerAndNothingElse(): bool
	{
		$version = str_repeat('a', 64);
		
		return CodesafeSender::shieldVersion('{"success":true,"accepted":1,"shield":"' . $version . '"}') === $version
			&& CodesafeSender::shieldVersion('{"success":true,"accepted":1}') === ''
			&& CodesafeSender::shieldVersion('{"shield":7}') === ''
			&& CodesafeSender::shieldVersion('<html>') === ''
			&& CodesafeSender::shieldVersion('') === '';
	}
	
	public function capturesDistinctThrowables(): bool
	{
		$sender = $this->makeSender();
		$first = new Exception('first');
		$second = new Exception('second');
		
		$sender->captureException($first);
		$sender->captureException($second);
		
		return $sender->queueCount() === 2;
	}
	
	/**
	 * Regression: the queue used to key throwables by spl_object_id without
	 * holding the object. Once the caller freed it, PHP recycled the handle
	 * and a later, different exception landed on the same id, silently
	 * overwriting the queued slot. Retention is the fix — and directly
	 * observable through a WeakReference.
	 */
	public function retainsQueuedThrowables(): bool
	{
		$sender = $this->makeSender();
		
		$first = new Exception('first');
		$weak = WeakReference::create($first);
		$sender->captureException($first);
		unset($first); // the caller drops its reference right away
		gc_collect_cycles();
		
		return $weak->get() !== null;
	}
	
	public function dedupesSameThrowableInstance(): bool
	{
		$sender = $this->makeSender();
		$exception = new Exception('boom');
		
		$sender->captureException($exception);
		$sender->captureException($exception);
		
		return $sender->queueCount() === 1;
	}
	
	public function queueIsCapped(): bool
	{
		$sender = $this->makeSender();
		
		for($i = 0; $i < CodesafeSender::QUEUE_MAX + 5; $i++)
		{
			$sender->captureMessage('message ' . $i);
		}
		
		return $sender->queueCount() === CodesafeSender::QUEUE_MAX;
	}
	
	/**
	 * Regression: the flush-time Events merge went through the same cap as
	 * explicit captures, so uncaught throwables were dropped whenever the
	 * queue was already full — exactly the errors that matter most.
	 */
	public function eventsMergeHasHeadroomPastTheCap(): bool
	{
		$sender = $this->makeSender();
		
		for($i = 0; $i < CodesafeSender::QUEUE_MAX; $i++)
		{
			$sender->captureMessage('message ' . $i);
		}
		
		$sender->captureException(new Exception('explicit — capped'));
		$cappedForCaptures = $sender->queueCount() === CodesafeSender::QUEUE_MAX;
		
		$sender->mergeEvent(new Exception('uncaught — must still queue'));
		
		return $cappedForCaptures
			&& $sender->queueCount() === CodesafeSender::QUEUE_MAX + 1;
	}
	
	public function disabledSenderCapturesNothing(): bool
	{
		$sender = $this->makeSender(enabled: false);
		
		$sender->captureException(new Exception('boom'));
		$sender->captureMessage('boom');
		
		return $sender->queueCount() === 0;
	}
	
	public function capture404QueuesAnInfoTypedPayload(): bool
	{
		$sender = $this->makeSender(report404: true);
		
		$sender->capture404('/wp-login.php');
		$payload = $sender->lastPayload();
		
		return $sender->queueCount() === 1
			&& ($payload['type'] ?? null) === '404'
			&& ($payload['kind'] ?? null) === 'not_found'
			&& ($payload['priority'] ?? null) === Priority::INFO
			&& ($payload['message'] ?? '') === '404 Not Found: /wp-login.php';
	}
	
	public function capture404DropsTheQueryString(): bool
	{
		$sender = $this->makeSender(report404: true);
		
		// the query is dropped so distinct probes stay distinct and any secret
		// in it never reaches the message
		$sender->capture404('/search?password=secret&q=1');
		
		return ($sender->lastPayload()['message'] ?? '') === '404 Not Found: /search';
	}
	
	public function capture404IsNoopUnlessEnabled(): bool
	{
		$sender = $this->makeSender(report404: false);
		
		$sender->capture404('/wp-login.php');
		
		return $sender->queueCount() === 0;
	}
	
	/**
	 * reportRefusal — the security-event channel's sender half. The KIND
	 * travels as the event's className (what the console indexes, filters
	 * and fingerprints by), the human line as the message, and the priority
	 * is INFO without the caller's word (below every kind's default, so the
	 * console applies the default). Placing the call is the app-side opt-in;
	 * there is deliberately no config switch.
	 */
	public function reportRefusalQueuesASecurityTypedPayload(): bool
	{
		$sender = $this->makeSender();
		
		$sender->reportRefusal('auth_failure', 'login failed for m***');
		$payload = $sender->lastPayload();
		
		return $sender->queueCount() === 1
			&& ($payload['type'] ?? null) === 'security'
			&& ($payload['kind'] ?? null) === 'security'
			&& ($payload['priority'] ?? null) === Priority::INFO
			&& ($payload['message'] ?? '') === 'login failed for m***'
			&& ($payload['events'][0]['className'] ?? null) === 'auth_failure'
			&& ($payload['events'][0]['message'] ?? null) === 'login failed for m***';
	}
	
	/**
	 * The Shield's matched fragment is the request's own text: by text it
	 * loses a password a pattern matched around and keeps the rest; the key
	 * walk alone sees only the key `matched`. Falsify: scrub the extra bag by
	 * key alone — `pwd=hunter2` travels to codesafe.
	 */
	public function aShieldFragmentLosesItsSecretsByText(): bool
	{
		$sender = $this->makeBatchSender();
		$sender->reportRefusal('shield_observe', 'shield rule 4 observed POST /login',
			['rule' => 4, 'matched' => 'log=admin&pwd=hunter2&rememberme=forever']);
		$extra = $sender->batch()[0]['context']['extra'] ?? [];
		
		return str_contains((string)($extra['matched'] ?? 'hunter2'), 'hunter2') === false
			&& str_contains((string)($extra['matched'] ?? ''), 'rememberme=forever')
			&& ($extra['rule'] ?? null) === 4;
	}
	
	/**
	 * The application's word that one matters (codesafe
	 * docs/plans/sender-security-priority.md): a priority it names travels,
	 * clamped to 0-7 — the console keeps it when it is more severe than the
	 * kind's default — and none named is INFO, as before.
	 */
	public function reportRefusalCarriesThePriorityTheAppNames(): bool
	{
		$sender = $this->makeSender();
		
		$sender->reportRefusal('auth_failure', 'admin login failed', [], [], Priority::ERROR);
		$raised = $sender->lastPayload();
		$sender->reportRefusal('auth_failure', 'out of range', [], [], -3);
		$clamped = $sender->lastPayload();
		$sender->reportRefusal('csrf_reject');
		$plain = $sender->lastPayload();
		
		return ($raised['priority'] ?? null) === Priority::ERROR
			&& ($clamped['priority'] ?? null) === Priority::EMERGENCY
			&& ($plain['priority'] ?? null) === Priority::INFO;
	}
	
	/**
	 * Three silences, each load-bearing: a kind outside the closed
	 * vocabulary is a no-op (the console refuses it wholesale, so sending
	 * would only waste the request — and a typo'd kind must not invent a
	 * category); the rolling cap turns a credential-stuffing wave into at
	 * most SECURITY_MAX_PER_MINUTE reports; a disabled sender reports
	 * nothing at all.
	 */
	public function reportRefusalRefusesUnknownKindsTheCapAndDisabled(): bool
	{
		$sender = $this->makeSender();
		$sender->reportRefusal('password_wrong', 'not a vocabulary kind');
		$unknownIsSilent = $sender->queueCount() === 0;
		
		$sender->securityAllowed = false;
		$sender->reportRefusal('auth_failure', 'over the cap');
		$cappedIsSilent = $sender->queueCount() === 0;
		
		$disabled = $this->makeSender(enabled: false);
		$disabled->reportRefusal('auth_failure', 'sender is off');
		
		return $unknownIsSilent
			&& $cappedIsSilent
			&& $disabled->queueCount() === 0;
	}
	
	/**
	 * RULE: the Shield's reports (shield_observe, shield_block, shield_rate)
	 * count against a budget of their own: a flood the Shield sees leaves the
	 * app's own security events reported, the app's cap leaves the Shield's
	 * alone, and the two counters never share a key. Falsify: count the
	 * Shield's kinds against allowSecurity() — an attacker tripping an
	 * observing rule silences the site's auth_failure and csrf_reject.
	 */
	public function theShieldsReportsHaveABudgetOfTheirOwn(): bool
	{
		$appCapped = $this->makeSender();
		$appCapped->securityAllowed = false;
		$appCapped->reportRefusal('shield_block', 'shield rule 7 refused GET /x');
		$shieldStillReports = $appCapped->queueCount() === 1;
		$appCapped->reportRefusal('auth_failure', 'over the app cap');
		
		$shieldCapped = $this->makeSender();
		$shieldCapped->shieldAllowed = false;
		$shieldCapped->reportRefusal('shield_observe', 'over the Shield cap');
		$shieldCapped->reportRefusal('csrf_reject', 'the app still reports');
		$minute = 29248320;
		
		return $shieldStillReports && $appCapped->queueCount() === 1
			&& $shieldCapped->queueCount() === 1
			&& CodesafeSender::securityKey('shop', $minute, true)
				=== 'shop:' . CodesafeSender::SECURITY_PREFIX . 'shield:' . $minute
			&& CodesafeSender::securityKey('shop', $minute, true)
				!== CodesafeSender::securityKey('shop', $minute);
	}
	
	/**
	 * RULE: the cap counts per INSTALL, not per pool. APCu belongs to the
	 * whole FPM pool and a pool can serve several installs, so one key for
	 * all of them means an attack wave against one spends the others'
	 * allowance — and their security events go unreported for as long as it
	 * lasts, which is exactly when they are worth having.
	 *
	 * An install that configures no cache prefix keeps the key it had.
	 */
	public function theSecurityCapCountsPerInstall(): bool
	{
		$minute = 29248320;
		
		return CodesafeSender::securityKey('shop', $minute)
				=== 'shop:' . CodesafeSender::SECURITY_PREFIX . $minute
			&& CodesafeSender::securityKey('shop', $minute)
				!== CodesafeSender::securityKey('tenant-b', $minute)
			// the same install still shares one counter across its workers
			&& CodesafeSender::securityKey('shop', $minute)
				=== CodesafeSender::securityKey('shop', $minute)
			&& CodesafeSender::securityKey('shop', $minute)
				!== CodesafeSender::securityKey('shop', $minute + 1)
			&& CodesafeSender::securityKey(null, $minute)
				=== CodesafeSender::SECURITY_PREFIX . $minute
			&& CodesafeSender::securityKey('', $minute)
				=== CodesafeSender::SECURITY_PREFIX . $minute;
	}
	
	/**
	 * The identity known at the call site rides the event: a login that just
	 * succeeded is reported before the session holds the user, so the caller
	 * names the account in a per-event context the flush lets win over the
	 * base it builds (ovos/console docs/plans/security-event-identity.md).
	 * Without one the payload carries no context of its own — the base alone
	 */
	public function reportRefusalCarriesThePerEventContext(): bool
	{
		$sender = $this->makeSender();
		
		$sender->reportRefusal('auth_success', 'login succeeded for m*** after 3 recent failures',
			['failures' => 3], ['userId' => '17']);
		$named = $sender->lastPayload();
		
		$sender->reportRefusal('auth_failure', 'login failed for m***');
		$plain = $sender->lastPayload();
		
		return ($named['context'] ?? null) === ['userId' => '17']
			&& ($named['extra']['failures'] ?? null) === 3
			&& ($named['events'][0]['className'] ?? null) === 'auth_success'
			&& isset($plain['context']) === false;
	}
	
	/** a call without a message still names its event — by its kind */
	public function reportRefusalWithoutAMessageNamesItsKind(): bool
	{
		$sender = $this->makeSender();
		
		$sender->reportRefusal('csrf_reject');
		$payload = $sender->lastPayload();
		
		return ($payload['message'] ?? '') === 'csrf_reject'
			&& ($payload['events'][0]['className'] ?? null) === 'csrf_reject';
	}
	
	/**
	 * The username mask call sites are told to use: every fourth character
	 * survives, the rest become stars (counted in CHARACTERS, so a multi-byte
	 * name masks like its ASCII twin), and the result is as long as the value
	 * was.
	 */
	public function maskNameKeepsEveryFourthCharacter(): bool
	{
		return CodesafeSender::maskName('bob') === 'b**'
			&& CodesafeSender::maskName('erin') === 'e***'
			&& CodesafeSender::maskName('marcin') === 'm***i*'
			&& CodesafeSender::maskName('marcinmarcin') === 'm***i***r***'
			&& CodesafeSender::maskName('Ökonom') === 'Ö***o*'
			// a single character has nothing to hide behind and no length to state
			&& CodesafeSender::maskName('a') === 'a'
			&& CodesafeSender::maskName('') === '';
	}
	
	/**
	 * Past MASK_MAX the stars stop and the mask states the real length: a login
	 * field holding 64 or 4000 characters is someone trying something, and the
	 * cut alone cannot tell that apart from a merely long name. A value exactly
	 * MASK_MAX long needs no statement — the mask already is that long.
	 */
	public function maskNameStatesTheLengthItCutOff(): bool
	{
		$full = str_repeat('x***', Logger::MASK_MAX / Logger::MASK_GROUP);
		
		return CodesafeSender::maskName(str_repeat('x', Logger::MASK_MAX)) === $full
			&& CodesafeSender::maskName(str_repeat('x', Logger::MASK_MAX + 1))
				=== $full . '[' . (Logger::MASK_MAX + 1) . ']'
			&& CodesafeSender::maskName(str_repeat('x', 200)) === $full . '[200]'
			// counted in characters, not bytes
			&& CodesafeSender::maskName(str_repeat('ä', 200))
				=== str_repeat('ä***', Logger::MASK_MAX / Logger::MASK_GROUP) . '[200]';
	}
	
	/**
	 * Masking twice is a no-op, and by construction rather than by a guard: the
	 * revealed positions of a mask hold the same characters again, and every
	 * other position is a star already. Scrubbing twice is normal — the console
	 * scrubs again server-side — and a second pass must not chew further into a
	 * value it has already masked.
	 */
	public function maskNameIsIdempotent(): bool
	{
		foreach(['a', 'bob', 'marcin', 'marcinmarcin', 'averylongusername',
			'abc***', str_repeat('x', Logger::MASK_MAX), str_repeat('x', 200),
			// a value that only LOOKS like a cut mask is still a name
			'admin[5]x'] as $value)
		{
			$masked = CodesafeSender::maskName($value);
			if(CodesafeSender::maskName($masked) !== $masked)
			{
				return false;
			}
		}
		
		return true;
	}
	
	public function notFoundExceptionBecomesA404WhenEnabled(): bool
	{
		$sender = $this->makeSender(report404: true);
		
		// the flush-time Events merge is where framework 404s arrive; the
		// router's own message is preserved ("File not found: …", a controller
		// miss — the subtype detail worth keeping), not flattened to a fixed text
		$sender->mergeEvent(new NotFoundException('File not found: themes/x/favicon.png'));
		$payload = $sender->lastPayload();
		
		return ($payload['type'] ?? null) === '404'
			&& ($payload['kind'] ?? null) === 'not_found'
			&& ($payload['priority'] ?? null) === Priority::INFO
			&& ($payload['message'] ?? '') === 'File not found: themes/x/favicon.png';
	}
	
	/**
	 * A 403 is a refusal (codesafe's failure-mode audit, D2): a
	 * ForbiddenException — the CLI-only gate a scanner hits over HTTP, an
	 * app's own refusal — is queued as the permission_denied security event at
	 * INFO, from the Events merge and from captureException() alike, once per
	 * throwable; past the security cap it is dropped, never sent as an error
	 */
	public function forbiddenExceptionIsAPermissionDeniedRefusal(): bool
	{
		$sender = $this->makeSender();
		$refusal = new ForbiddenException('Forbidden.');
		$sender->mergeEvent($refusal);
		$sender->captureException($refusal);
		$payload = $sender->lastPayload();
		
		$captured = $this->makeSender();
		$captured->captureException(new ForbiddenException('not yours'));
		
		$capped = $this->makeSender();
		$capped->securityAllowed = false;
		$capped->mergeEvent(new ForbiddenException('Forbidden.'));
		
		return $sender->queueCount() === 1
			&& ($payload['type'] ?? null) === 'security'
			&& ($payload['kind'] ?? null) === 'security'
			&& ($payload['priority'] ?? null) === Priority::INFO
			&& ($payload['events'][0]['className'] ?? null) === 'permission_denied'
			&& ($payload['message'] ?? '') === 'Forbidden.'
			&& ($captured->lastPayload()['events'][0]['className'] ?? null) === 'permission_denied'
			&& $capped->queueCount() === 0;
	}
	
	/**
	 * With 404 reporting off a routing miss is not reported at all — never as
	 * an application error, which is what it used to become: codesafe's FLEET
	 * read bo2go FAILING at 52% on an hour of a scanner's probes filed as
	 * errors (2026-10-05). The router's own FileNotFoundException, INFO as the
	 * router throws it, and a plain NotFoundException alike; an application
	 * error beside them still goes
	 */
	public function notFoundExceptionIsNotReportedAtAllWhenDisabled(): bool
	{
		$sender = $this->makeSender(report404: false);
		
		$sender->mergeEvent(new NotFoundException('No route'));
		$sender->mergeEvent((new FileNotFoundException('File not found: %s', 'wp-login.php'))->withPriority(Priority::INFO));
		$quiet = $sender->queueCount();
		$sender->mergeEvent(new RuntimeException('a real failure'));
		
		return $quiet === 0
			&& $sender->queueCount() === 1
			&& ($sender->lastPayload()['message'] ?? '') === 'a real failure';
	}
	
	/**
	 * …and with it on, the router's FileNotFoundException is a 404 like any
	 * routing miss — the kind, INFO, the router's own message kept
	 */
	public function theRoutersFileNotFoundIsA404WhenEnabled(): bool
	{
		$sender = $this->makeSender(report404: true);
		
		$sender->mergeEvent((new FileNotFoundException('File not found: %s', 'wp-login.php'))->withPriority(Priority::INFO));
		$payload = $sender->lastPayload();
		
		return ($payload['kind'] ?? null) === 'not_found'
			&& ($payload['type'] ?? null) === '404'
			&& ($payload['priority'] ?? null) === Priority::INFO
			&& ($payload['message'] ?? '') === 'File not found: wp-login.php';
	}
	
	/**
	 * PHP refusing a request body during startup (a malformed multipart POST
	 * from an upload-exploit scanner) reaches the merge as the ErrorException
	 * handleShutdown() builds from error_get_last(). The application never had
	 * a say — access noise like a router 404, NOT gated by the report_404
	 * opt-in (which exists because a routing miss can be a real broken link).
	 */
	public function requestStartupRefusalIsAccessNoiseEvenWithout404Optin(): bool
	{
		$sender = $this->makeSender(report404: false);
		
		$message = 'PHP Request Startup: Missing boundary in multipart/form-data POST data';
		$sender->mergeEvent(new ErrorException($message,
			0,
			E_WARNING,
			'Unknown',
			0,
		));
		$payload = $sender->lastPayload();
		
		return ($payload['type'] ?? null) === '404'
			&& ($payload['priority'] ?? null) === Priority::INFO
			&& ($payload['message'] ?? '') === $message;
	}
	
	/**
	 * Guard against overbroad matching: only the "PHP Request Startup: "
	 * prefix marks a warning as client-caused — an ordinary runtime warning
	 * keeps its place in the error band.
	 */
	public function ordinaryWarningStaysInTheErrorBand(): bool
	{
		$sender = $this->makeSender(report404: false);
		
		$sender->mergeEvent(new ErrorException('Undefined array key "boundary"',
			0,
			E_WARNING,
			'/app/src/Upload.php',
			42,
		));
		$payload = $sender->lastPayload();
		
		return ($payload['type'] ?? null) === null
			&& ($payload['priority'] ?? null) === Priority::WARNING;
	}
	
	/**
	 * The response status the failing request ended with (context.status):
	 * what http_response_code() answers when it is an int in the HTTP range,
	 * else nothing — false (no SAPI status) and out-of-range values never
	 * become a context key, so the console never indexes a made-up status
	 */
	public function responseStatusIsTheSapiStatusInTheHttpRange(): bool
	{
		return CodesafeSender::statusOf(500) === 500
			&& CodesafeSender::statusOf(200) === 200
			&& CodesafeSender::statusOf(100) === 100
			&& CodesafeSender::statusOf(599) === 599
			&& CodesafeSender::statusOf(false) === null
			&& CodesafeSender::statusOf(null) === null
			&& CodesafeSender::statusOf(99) === null
			&& CodesafeSender::statusOf(600) === null
			&& CodesafeSender::statusOf('500') === null;
	}
	
	/**
	 * RULE: a CDN in front of the application already resolved the visitor's
	 * country to route the request, so context.country carries what the EDGE
	 * said — Cloudflare's CF-IPCountry or CloudFront's Viewer-Country, upper-
	 * cased. Nothing where there is no edge: the console then answers from its
	 * own table, and a missing key is how it knows to.
	 *
	 * Cloudflare's two non-countries never travel: XX is "could not tell" and
	 * T1 means the request came out of Tor — true, and not a country.
	 */
	public function theEdgesCountryRidesAlongWhenThereIsAnEdge(): bool
	{
		$sender = $this->makeSender();
		$of = function (array $headers) use ($sender): string
		{
			foreach(['HTTP_CF_IPCOUNTRY', 'HTTP_CLOUDFRONT_VIEWER_COUNTRY'] as $key)
			{
				unset($_SERVER[$key]);
			}
			foreach($headers as $key => $value)
			{
				$_SERVER[$key] = $value;
			}
			$answer = $sender->country();
			foreach(array_keys($headers) as $key)
			{
				unset($_SERVER[$key]);
			}
			
			return $answer;
		};
		
		return $of([]) === ''
			&& $of(['HTTP_CF_IPCOUNTRY' => 'AT']) === 'AT'
			&& $of(['HTTP_CF_IPCOUNTRY' => 'at']) === 'AT'
			&& $of(['HTTP_CLOUDFRONT_VIEWER_COUNTRY' => 'JP']) === 'JP'
			// Cloudflare first where both are present: it is the nearer edge
			&& $of(['HTTP_CF_IPCOUNTRY' => 'DE', 'HTTP_CLOUDFRONT_VIEWER_COUNTRY' => 'JP']) === 'DE'
			&& $of(['HTTP_CF_IPCOUNTRY' => 'XX']) === ''
			&& $of(['HTTP_CF_IPCOUNTRY' => 'T1']) === ''
			&& $of(['HTTP_CF_IPCOUNTRY' => 'AUT']) === ''
			&& $of(['HTTP_CF_IPCOUNTRY' => '<script>']) === '';
	}
	
	/**
	 * Under the CLI SAPI http_response_code() answers false — a CLI run has
	 * no response, so the sender adds no status rather than a default one
	 */
	public function cliRunsCarryNoResponseStatus(): bool
	{
		return $this->makeSender()->status() === null;
	}
	
	/**
	 * @return CodesafeSender&object{queueCount: callable(): int}
	 */
	/**
	 * The replay keys (docs/SENDER.md §context.request): the raw BODY with its
	 * content type and the headers that change what the application answers —
	 * what the console's replay needs to re-issue the request that FAILED.
	 *
	 * A JSON API call's $_POST is EMPTY (the body is a stream PHP never
	 * populates), so without these the console replays such a write as a bare
	 * method and URL — a different request wearing the same name, whose
	 * verdict is wrong rather than missing.
	 *
	 * Prevents, on the sending side: shipping a cookie or an authorization
	 * header, the project key a sender's call to codesafe carries, and
	 * shipping the credential inside a body whole.
	 */
	public function theRequestCarriesTheBodyAndItsSafeHeaders(): bool
	{
		$sender = $this->makeSender();
		$server = $_SERVER;
		$post = $_POST;
		$_POST = [];
		// the suite shares $_SERVER, so another test's headers would otherwise
		// land in the exact comparison below
		foreach($_SERVER as $key => $value)
		{
			if(str_starts_with((string)$key, 'HTTP_'))
			{
				unset($_SERVER[$key]);
			}
		}
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_SERVER['CONTENT_TYPE'] = 'application/json';
		$_SERVER['HTTP_ACCEPT'] = 'application/json';
		$_SERVER['HTTP_X_TENANT_ID'] = 'acme';
		$_SERVER['HTTP_COOKIE'] = 'session=abc';
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer abc.def';
		$_SERVER['HTTP_X_API_KEY'] = 'k-1';
		$_SERVER['HTTP_X_CODESAFE_KEY'] = 'project-key-1';
		$_SERVER['HTTP_X_CONSOLE_KEY'] = 'project-key-1';
		$_SERVER['HTTP_VIA'] = '1.1 proxy';
		try
		{
			$request = $sender->request(new Logger);
			// a multipart body is never read: megabytes of binary, and $_POST
			// already carries its fields
			$multipart = $sender->body('multipart/form-data; boundary=x');
			$_SERVER['REQUEST_METHOD'] = 'GET';
			$read = $sender->body('application/json');
		}
		finally
		{
			$_SERVER = $server;
			$_POST = $post;
		}
		
		return ($request['contentType'] ?? '') === 'application/json'
			&& ($request['headers'] ?? []) === ['accept' => 'application/json', 'x-tenant-id' => 'acme']
			// php://input is empty in a CLI test run, so the key stays out
			&& array_key_exists('body', $request) === false
			&& $multipart === '' && $read === ''
			&& CodesafeSender::BODY_MAX === 16384
			&& in_array('accept-language', CodesafeSender::REQUEST_HEADERS, true);
	}
	
	/**
	 * The browser's Fetch Metadata travels with the request, first and as
	 * tokens — lowercase, nothing but `[a-z0-9?-]`, 32 characters, one with
	 * no token left out — so the cap never pushes it out of a request with
	 * thirty `x-…` headers, and no secret name of the Logger takes
	 * `sec-fetch-user`. codesafe reads it to tell a request a page on another
	 * site made a visitor's browser send from one the visitor sent.
	 *
	 * Falsify: take them in the loop as any other header (not on the list,
	 * dropped), or send the value as it came.
	 */
	public function theFetchMetadataTravelsAsTokens(): bool
	{
		$sender = $this->makeSender();
		$server = $_SERVER;
		foreach($_SERVER as $key => $value)
		{
			if(str_starts_with((string)$key, 'HTTP_'))
			{
				unset($_SERVER[$key]);
			}
		}
		for($n = 10; $n < 40; $n++)
		{
			$_SERVER['HTTP_X_H_' . $n] = 'v';
		}
		$_SERVER['HTTP_SEC_FETCH_SITE'] = 'Cross-Site';
		$_SERVER['HTTP_SEC_FETCH_MODE'] = "no-cors\r\n";
		$_SERVER['HTTP_SEC_FETCH_DEST'] = str_repeat('image', 10);
		$_SERVER['HTTP_SEC_FETCH_USER'] = '?1';
		try
		{
			$crowded = $sender->request(new Logger)['headers'] ?? [];
			$_SERVER['HTTP_SEC_FETCH_SITE'] = '<>';
			$_SERVER['HTTP_SEC_FETCH_MODE'] = ['cors'];
			$empty = CodesafeSender::fetchHeaders($_SERVER);
		}
		finally
		{
			$_SERVER = $server;
		}
		
		return count($crowded) === CodesafeSender::HEADERS_MAX
			&& array_slice($crowded, 0, 4, true) === ['sec-fetch-site' => 'cross-site', 'sec-fetch-mode' => 'no-cors',
				'sec-fetch-dest' => str_repeat('image', 6) . 'im', 'sec-fetch-user' => '?1']
			&& isset($crowded['x-h-29']) && isset($crowded['x-h-30']) === false
			&& $empty === ['sec-fetch-dest' => str_repeat('image', 6) . 'im', 'sec-fetch-user' => '?1'];
	}
	
	/**
	 * The request data keeps its people and loses its secrets (console
	 * docs/plans/reveal-everything.md): an e-mail and a username in $_GET and
	 * $_POST travel as sent — the console masks them and keeps the original
	 * encrypted — while a password is dropped here as always
	 */
	public function theRequestDataKeepsItsPeopleAndLosesItsSecrets(): bool
	{
		$sender = $this->makeSender();
		$get = $_GET;
		$post = $_POST;
		$_GET = ['ref' => 'mail', 'email' => 'anna@example.at'];
		$_POST = ['billing_email' => 'anna.berger@example.com', 'username' => 'annab', 'password' => 'hunter22'];
		try
		{
			$request = $sender->request(new Logger);
		}
		finally
		{
			$_GET = $get;
			$_POST = $post;
		}
		
		return ($request['get']['email'] ?? '') === 'anna@example.at'
			&& ($request['post']['billing_email'] ?? '') === 'anna.berger@example.com'
			&& ($request['post']['username'] ?? '') === 'annab'
			&& ($request['post']['password'] ?? '') === '[redacted]';
	}
	
	protected function makeSender(
		bool $enabled = true,
		bool $report404 = false,
	): CodesafeSender
	{
		$config = new ArrayObject([
			'enabled' => $enabled,
			'url' => 'https://console.invalid',
			'key' => 'test-key',
			'report_404' => $report404,
		]);
		
		return new class($config) extends CodesafeSender
		{
			/**
			 * The rolling APCu cap, scripted: the real allowSecurity() reads a
			 * shared per-minute counter, so suite runs inside one minute would
			 * interfere with each other's counts
			 */
			public bool $securityAllowed = true;
			
			/** the Shield's own cap, scripted the same way */
			public bool $shieldAllowed = true;
			
			protected function allowSecurity(): bool
			{
				return $this->securityAllowed;
			}
			
			protected function allowShield(): bool
			{
				return $this->shieldAllowed;
			}
			
			public function queueCount(): int
			{
				return count($this->queue);
			}
			
			/**
			 * What buildContext() would put under context.status
			 */
			public function status(): ?int
			{
				return $this->responseStatus();
			}
			
			/**
			 * …and what it would put under context.country
			 */
			public function country(): string
			{
				return self::edgeCountry();
			}
			
			/**
			 * @return array<string, mixed> the most recently queued payload
			 */
			public function lastPayload(): array
			{
				return $this->queue === []
					? []
					: $this->queue[count($this->queue) - 1];
			}
			
			/**
			 * The flush-time Events merge path, byte-for-byte
			 */
			public function mergeEvent(
				Throwable $event,
			): void
			{
				$this->enqueue($this->event()->exception($event), self::QUEUE_MAX * 2);
			}
			
			protected function send(
				string $json,
			): void
			{
				// never post from tests
			}
			
			/** what buildRequest() puts under context.request (docs/SENDER.md) */
			public function request(
				Logger $logger,
			): array
			{
				return $this->buildRequest($logger);
			}
			
			/** the raw body it would read for a content type */
			public function body(
				string $contentType,
			): string
			{
				return $this->readBody($contentType);
			}
		};
	}
	/**
	 * The deploy label (ovos/php-module-system `release stamp`): the configured
	 * console.release wins whenever it is non-empty — a placeholder like "dev"
	 * included, deliberately — else the stamp's first line, trimmed and capped;
	 * nothing anywhere is '' (the behaviour before the stamp), and an absent
	 * or unreadable stamp reads as no stamp
	 */
	public function theReleaseIsTheConfiguredValueElseTheStamp(): bool
	{
		return CodesafeSender::releaseLabel('v1.2', "abc123\n") === 'v1.2'
			&& CodesafeSender::releaseLabel('dev', "abc123\n") === 'dev'
			&& CodesafeSender::releaseLabel('', "abc123\n") === 'abc123'
			&& CodesafeSender::releaseLabel(null, "abc123\nsecond line\n") === 'abc123'
			&& CodesafeSender::releaseLabel('  ', "  7f3e9  \n") === '7f3e9'
			&& CodesafeSender::releaseLabel('', null) === ''
			// a non-string configured value is no value: the stamp speaks, or nothing does
			&& CodesafeSender::releaseLabel(42, "abc123\n") === 'abc123'
			&& CodesafeSender::releaseLabel(42, '') === ''
			&& mb_strlen(CodesafeSender::releaseLabel(str_repeat('x', 80), null)) === CodesafeSender::RELEASE_MAX
			&& CodesafeSender::stamp(__DIR__ . '/no-such-file.release') === null
			&& CodesafeSender::RELEASE_FILE === '.release';
	}
	
	/**
	 * console.tags stamps a deployment's tags on every event of the batch
	 * (ovos/console docs/plans/event-tags.md): a yml list (an ArrayObject by
	 * the time it is config), a plain array or ONE comma string — trimmed,
	 * empties dropped, at most ten; nothing configured means no field at all,
	 * not an empty list
	 */
	public function theBatchCarriesTheConfiguredTags(): bool
	{
		$make = static function(mixed $tags): CodesafeSender
		{
			$config = new ArrayObject([
				'enabled' => true,
				'url' => 'https://console.invalid',
				'key' => 'test-key',
			] + ($tags === null ? [] : ['tags' => $tags]));
			
			$sender = container()->injectMissing(new class($config) extends CodesafeSender
			{
				public function batch(): array
				{
					return $this->buildBatch();
				}
			});
			$sender->captureException(new Exception('boom'));
			
			return $sender;
		};
		
		$yml = $make(new ArrayObject(['shop', 'eu']));
		$list = $make(['shop', ' eu ', '', 7]);
		$string = $make(' shop, eu;tenant:acme ');
		$none = $make(null);
		
		return ($yml->batch()[0]['tags'] ?? null) === ['shop', 'eu']
			&& ($list->batch()[0]['tags'] ?? null) === ['shop', 'eu', '7']
			&& ($string->batch()[0]['tags'] ?? null) === ['shop', 'eu', 'tenant:acme']
			&& isset($none->batch()[0]['tags']) === false;
	}
	
	/**
	 * The untracked pass (SENDER.md §7 "Files"): from the CLI, the report the
	 * working copy answers goes to /api/v1/ingest/files with the console's
	 * own shape and the labels the events carry, and the answer is the
	 * console's code; an empty answer still posts (GONE needs it); nothing is
	 * known — and nothing posts — when the working copy does not answer or
	 * the sender is off
	 */
	public function reportUntrackedPostsTheWorkingCopysAnswerFromTheCli(): bool
	{
		$make = static function(bool $enabled, ?string $output): CodesafeSender
		{
			$config = new ArrayObject([
				'enabled' => $enabled,
				'url' => 'https://console.invalid/',
				'key' => 'test-key',
				'release' => 'r77',
				'environment' => 'staging',
				'files' => ['web' => ['www']],
			]);
			
			return container()->injectMissing(new class($config, $output) extends CodesafeSender
			{
				public array $posts = [];
				
				public function __construct(
					ArrayObject $config,
					protected ?string $output,
				)
				{
					parent::__construct($config);
				}
				
				protected function workingCopy(): ?array
				{
					return ['root' => '/srv/site', 'vcs' => Untracked::GIT];
				}
				
				protected function untracked(): Untracked
				{
					// the untracked list is scripted; the diff names one modified file under www
					return new Untracked(fn(array $command, string $cwd, int $timeoutMs): ?string
						=> in_array('diff', $command, true) ? ($this->output === null ? null : "M\0www/lib.php\0") : $this->output);
				}
				
				protected function post(
					string $path,
					string $json,
				): int
				{
					$this->posts[] = [$path, $json];
					
					return 202;
				}
			});
		};
		
		$sender = $make(true, "www/x.php\0docs/a.pdf\0");
		$accepted = $sender->reportUntracked(['mode' => Untracked::MODE_MANUAL]);
		$report = json_decode($sender->posts[0][1] ?? '', true) ?? [];
		
		$empty = $make(true, '');
		$emptyAccepted = $empty->reportUntracked();
		$emptyReport = json_decode($empty->posts[0][1] ?? '', true) ?? [];
		$emptyReport['findings'] = array_filter($emptyReport['findings'] ?? [], static fn(array $row): bool => $row['detector'] !== 'modified');
		
		$silent = $make(true, null);
		$off = $make(false, "www/x.php\0");
		
		return $accepted === true
			&& count($sender->posts) === 1
			&& $sender->posts[0][0] === '/api/v1/ingest/files'
			&& ($report['platform'] ?? '') === 'php'
			&& ($report['type'] ?? '') === 'files'
			&& ($report['release'] ?? '') === 'r77'
			&& ($report['environment'] ?? '') === 'staging'
			&& ($report['scan']['mode'] ?? '') === 'manual'
			&& ($report['areas']['root']['root'] ?? '') === '/srv/site'
			// console.files.web named www, so the PHP under it is urgent — the modified one too
			&& ($report['findings'][0]['path'] ?? '') === 'www/lib.php'
			&& ($report['findings'][0]['detector'] ?? '') === 'modified'
			&& ($report['findings'][0]['tier'] ?? '') === 'urgent'
			&& ($report['findings'][1]['path'] ?? '') === 'www/x.php'
			&& ($report['findings'][1]['tier'] ?? '') === 'urgent'
			&& ($report['findings'][2]['detector'] ?? '') === 'untracked_dir'
			&& ($report['areas']['root']['modified'] ?? null) === 1
			&& ($report['posture']['working_copy'] ?? '') === 'git'
			&& $emptyAccepted === true
			&& ($emptyReport['findings'] ?? null) === []
			&& ($emptyReport['areas']['root']['foreign'] ?? null) === 0
			&& $silent->reportUntracked() === false && $silent->posts === []
			&& $off->reportUntracked() === false && $off->posts === []
			&& $off->untrackedReport() === null;
	}
	
	/**
	 * The identity round (codesafe docs/plans/identity-round-2026-10.md): a
	 * CLI batch sends its people raw and its secrets never. The extras keep
	 * an e-mail and a username, the arguments an e-mail, and the message its
	 * address — codesafe masks them on arrival and keeps the originals in the
	 * vault for an audited REVEAL — while a secret extra, `--password=x` and
	 * the value after a bare secret name are dropped here as always; the
	 * OTLP form of the same batch carries the same values
	 */
	public function theBatchSendsPeopleRawAndSecretsNever(): bool
	{
		$argv = $_SERVER['argv'] ?? null;
		$_SERVER['argv'] = ['cli.php', 'mail', '--to=anna@example.at', '--password=hunter2',
			'api_key', 'k-1', 'bob@x.co'];
		try
		{
			$sender = $this->makeBatchSender();
			$sender->captureException(new Exception('mail to anna@example.at failed'), [
				'email' => 'anna.berger@example.com',
				'username' => 'annab',
				'password' => 'hunter22',
				'note' => 'cc bob@x.co',
			]);
			$batch = $sender->batch();
		}
		finally
		{
			if($argv === null)
			{
				unset($_SERVER['argv']);
			}
			else
			{
				$_SERVER['argv'] = $argv;
			}
		}
		
		$context = $batch[0]['context'] ?? [];
		$otlp = json_encode(Otlp::request($batch), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
		
		return ($context['extra'] ?? null) === [
				'email' => 'anna.berger@example.com',
				'username' => 'annab',
				'password' => '[redacted]',
				'note' => 'cc bob@x.co',
			]
			&& ($context['args'] ?? null) === ['cli.php', 'mail', '--to=anna@example.at',
				'--password=[redacted]', 'api_key', '[redacted]', 'bob@x.co']
			&& ($batch[0]['message'] ?? '') === 'mail to anna@example.at failed'
			&& str_contains($otlp, 'anna.berger@example.com')
			&& str_contains($otlp, '--to=anna@example.at')
			&& str_contains($otlp, 'hunter2') === false;
	}
	
	/**
	 * Under a Logger that masks people, the batch still carries the e-mail
	 * addresses and the usernames codesafe vaults (USERNAMES) raw, and masks
	 * a username field the application added, which codesafe would keep in
	 * clear.
	 */
	public function theBatchMasksOnlyTheUsernameFieldsCodesafeCannotVault(): bool
	{
		$sender = container()->injectMissing(new class(new ArrayObject([
			'enabled' => true,
			'url' => 'https://console.invalid',
			'key' => 'test-key',
		])) extends CodesafeSender
		{
			public function batch(): array
			{
				return $this->buildBatch();
			}
			
			protected function getLogger(): Logger
			{
				return (new Logger)->addUsernames(['~^nick$~i'])->maskingIdentities();
			}
		});
		$sender->captureException(new Exception('profile update failed'), [
			'email' => 'anna.berger@example.com',
			'username' => 'annab',
			'nick' => 'marcin',
			'password' => 'hunter22',
		]);
		
		return ($sender->batch()[0]['context']['extra'] ?? null) === [
			'email' => 'anna.berger@example.com',
			'username' => 'annab',
			'nick' => 'm***i*',
			'password' => '[redacted]',
		];
	}
	
	/**
	 * …and a web batch its uri and referer: the e-mail in the path and in
	 * the query and a `login` param travel as sent, a token in the query and
	 * a token-shaped path segment never; the request bags keep their people
	 * beside them
	 */
	public function theUriAndRefererKeepTheirPeopleAndLoseTheirSecrets(): bool
	{
		$server = $_SERVER;
		$get = $_GET;
		$_GET = ['email' => 'anna@example.at'];
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_SERVER['REQUEST_URI'] = '/unsubscribe/john@x.com?email=anna%40example.at&login=marcin&token=abc123';
		$_SERVER['HTTP_REFERER'] = 'https://shop.example/reset/eyJhbGciOiJIUzI1NiJ9.payloadpayload.sigsig'
			. '?user=annab&key=k1';
		try
		{
			$sender = $this->makeBatchSender();
			$sender->useApp(new class extends Application
			{
				/** a web request, without booting a second application */
				public function __construct()
				{
				}
				
				public function getServices(): Services
				{
					return Application::$instance->getServices();
				}
				
				public function getEnv(): string
				{
					return Application::$instance->getEnv();
				}
			});
			$sender->captureException(new Exception('boom'));
			$batch = $sender->batch();
		}
		finally
		{
			$_SERVER = $server;
			$_GET = $get;
		}
		
		$context = $batch[0]['context'] ?? [];
		
		return ($batch[0]['entry'] ?? '') === 'web'
			&& ($context['uri'] ?? '')
				=== '/unsubscribe/john@x.com?email=anna%40example.at&login=marcin&token=[redacted]'
			&& ($context['referer'] ?? '')
				=== 'https://shop.example/reset/[redacted]?user=annab&key=[redacted]'
			&& ($context['request']['get'] ?? null) === ['email' => 'anna@example.at'];
	}
	
	/**
	 * RULE: an event sent at the shutdown flush carries the route that
	 * answered as context.route, the rollup's name for it: the dispatched
	 * handler's route on a 200 and on its 500, __unmatched on a 404 and
	 * where nothing was dispatched, whatever the error page answers (on a
	 * 2xx with the miss among the collected events).
	 * Falsify: leave the route out of buildContext(), or read the controller
	 * instance (the error page's) in Rollup::routeOf() — the 500 sends
	 * __unmatched and the undispatched probe `/wp-admin/index`.
	 */
	public function theShutdownFlushSendsTheRouteThatAnswered(): bool
	{
		$handler = $this->sharedRequestKept(static fn(): Controller => new class extends Controller
		{
			public function item(): void
			{
			}
		});
		$errorPage = $this->sharedRequestKept(static fn(): Controller => new class extends Controller
		{
			public function index(): void
			{
			}
		});
		
		$dispatched = new Request;
		$dispatched->setController('news');
		$dispatched->setAction('item');
		$dispatched->setActionMethod('item');
		$dispatched->setDispatchedController($handler);
		// the shutdown's error page claims the instance after the handler threw
		$dispatched->setControllerInstance($errorPage);
		
		$undispatched = new Request;
		$undispatched->setController('wp-admin');
		$undispatched->setAction('index');
		$undispatched->setControllerInstance($errorPage);
		
		$route = function(Request $request, int $status, Throwable ...$collected): mixed
		{
			$sender = $this->makeRouteSender($this->webApp($request, null, self::collected(...$collected)), $status);
			$sender->captureException(new Exception('route probe'));
			$sender->terminate();
			
			return self::sentContext($sender, 'route probe')['route'] ?? null;
		};
		
		return $route($dispatched, 200) === '/news/item'
			&& $route($dispatched, 500) === '/news/item'
			&& $route($dispatched, 404) === '__unmatched'
			&& $route($undispatched, 200, new NotFoundException('No controller')) === '__unmatched'
			&& $route($undispatched, 500) === '__unmatched';
	}
	
	/**
	 * RULE: on a 2xx, __unmatched tells codesafe no handler took the request
	 * and the error page answered it, so a request nothing was dispatched
	 * for names it only with the miss on record: a NotFoundException among
	 * the collected events (the router's static-file miss is one). Without
	 * it, or with another failure only, code before the dispatcher answered,
	 * and the event names no route.
	 * Falsify: send __unmatched on a 2xx whatever was collected — the 200
	 * and the 204 without events send it; take any collected throwable for
	 * the miss — the RuntimeException sends it; ask for the miss on a 200
	 * alone — the 204 sends it; never accept the miss — the
	 * NotFoundException sends none.
	 */
	public function anUndispatchedSuccessNamesTheMissOnlyOnRecord(): bool
	{
		$undispatched = new Request;
		$undispatched->setController('wp-admin');
		$undispatched->setAction('index');
		
		$route = function(int $status, Throwable ...$collected) use ($undispatched): ?array
		{
			$sender = $this->makeRouteSender($this->webApp($undispatched, null, self::collected(...$collected)), $status);
			$sender->captureException(new Exception('soft miss'));
			$sender->terminate();
			
			return self::sentContext($sender, 'soft miss');
		};
		
		$answered = $route(200);
		$empty = $route(204);
		$failed = $route(200, new RuntimeException('resolver failed'));
		$missed = $route(200, new NotFoundException('No controller'));
		$file = $route(200, new FileNotFoundException('File not found'));
		
		return $answered !== null && array_key_exists('route', $answered) === false
			&& ($answered['status'] ?? null) === 200
			&& $empty !== null && array_key_exists('route', $empty) === false
			&& $failed !== null && array_key_exists('route', $failed) === false
			&& ($missed['route'] ?? null) === '__unmatched'
			&& ($file['route'] ?? null) === '__unmatched'
			// the rollup counts every one of them under __unmatched
			&& CodesafeRollup::routeFor(200, $undispatched) === '__unmatched';
	}
	
	/**
	 * RULE: a dispatched handler whose action does not resolve (renamed
	 * after the dispatch to one its class lacks) answered the request
	 * itself: on a 2xx no route, a collected NotFoundException or not; on a
	 * 404 or a 500 __unmatched, as the rollup counts it.
	 * Falsify: let a collected NotFoundException stand for the miss without
	 * asking whether a handler was dispatched — the 200 with one sends
	 * __unmatched; send __unmatched on a 2xx whatever was collected — both
	 * 200s send it; ask for the miss on every status — the 404 and the 500
	 * send none.
	 */
	public function aHandlerWhoseActionIsGoneNamesNoRouteOnSuccess(): bool
	{
		$handler = $this->sharedRequestKept(static fn(): Controller => new class extends Controller
		{
			public function item(): void
			{
			}
		});
		
		$renamed = new Request;
		$renamed->setController('news');
		$renamed->setAction('item');
		$renamed->setActionMethod('item');
		$renamed->setDispatchedController($handler);
		// after the dispatch, to an action the handler's class lacks
		$renamed->setAction('gone');
		$renamed->setActionMethod('gone');
		
		$route = function(int $status, Throwable ...$collected) use ($renamed): ?array
		{
			$sender = $this->makeRouteSender($this->webApp($renamed, null, self::collected(...$collected)), $status);
			$sender->captureException(new Exception('gone action'));
			$sender->terminate();
			
			return self::sentContext($sender, 'gone action');
		};
		
		$answered = $route(200);
		$missed = $route(200, new NotFoundException('No action'));
		
		return $answered !== null && array_key_exists('route', $answered) === false
			&& $missed !== null && array_key_exists('route', $missed) === false
			&& ($route(404)['route'] ?? null) === '__unmatched'
			&& ($route(500)['route'] ?? null) === '__unmatched'
			&& CodesafeRollup::routeFor(200, $renamed) === '__unmatched';
	}
	
	/**
	 * RULE: a request nothing was dispatched for keeps __unmatched on every
	 * status but a 2xx, nothing collected: a 404, a 500, a redirect, and a
	 * web request whose status cannot be read.
	 * Falsify: ask for the miss on every status — the 404, the 500 and the
	 * redirect send no route; on a 3xx too — the redirect sends none.
	 */
	public function anUndispatchedFailureStaysUnmatched(): bool
	{
		$undispatched = new Request;
		$undispatched->setController('wp-admin');
		$undispatched->setAction('index');
		
		$route = function(?int $status) use ($undispatched): mixed
		{
			$sender = $this->makeRouteSender($this->webApp($undispatched), $status);
			$sender->captureException(new Exception('hard miss'));
			$sender->terminate();
			
			return self::sentContext($sender, 'hard miss')['route'] ?? null;
		};
		
		return $route(404) === '__unmatched'
			&& $route(500) === '__unmatched'
			&& $route(302) === '__unmatched'
			&& $route(null) === '__unmatched';
	}
	
	/**
	 * RULE: only the shutdown flush names the route. A flush in the middle of
	 * a request, where the error page may still take the request over, a CLI
	 * run and an application that cannot tell its request send no
	 * context.route at all, never a default; the same request at the
	 * shutdown does.
	 * Falsify: leave the route out of buildContext() (the shutdown sends none
	 * either), set it on every web flush (drop the terminate() flag — the
	 * mid-request flush sends `/news/item`), outside the web branch (the CLI
	 * sends the runner's own route), or send __unmatched where the request
	 * cannot be read.
	 */
	public function onlyTheShutdownFlushSendsARoute(): bool
	{
		$handler = $this->sharedRequestKept(static fn(): Controller => new class extends Controller
		{
			public function item(): void
			{
			}
		});
		
		$request = new Request;
		$request->setController('news');
		$request->setAction('item');
		$request->setActionMethod('item');
		$request->setDispatchedController($handler);
		
		$midRequest = $this->makeRouteSender($this->webApp($request), 200);
		$midRequest->captureException(new Exception('mid-request flush'));
		$midRequest->flush();
		
		$shutdown = $this->makeRouteSender($this->webApp($request), 200);
		$shutdown->captureException(new Exception('shutdown flush'));
		$shutdown->terminate();
		
		$blind = $this->makeRouteSender($this->webApp(null), 200);
		$blind->captureException(new Exception('blind flush'));
		$blind->terminate();
		
		// the runner's own application: a CLI run
		$cli = $this->makeRouteSender(null, null);
		$cli->captureException(new Exception('cli flush'));
		$cli->terminate();
		
		$mid = self::sentContext($midRequest, 'mid-request flush');
		$ended = self::sentContext($shutdown, 'shutdown flush');
		$run = self::sentContext($cli, 'cli flush');
		$unknown = self::sentContext($blind, 'blind flush');
		
		return $mid !== null && array_key_exists('route', $mid) === false
			&& ($mid['status'] ?? null) === 200
			&& ($ended['route'] ?? null) === '/news/item'
			&& $run !== null && array_key_exists('route', $run) === false
			&& array_key_exists('args', $run)
			&& $unknown !== null && array_key_exists('route', $unknown) === false
			&& ($unknown['status'] ?? null) === 200;
	}
	
	/**
	 * RULE: a request a plugin answered before the action (a CSRF refusal, a
	 * Shield block, a page served from the cache) sends no context.route:
	 * the handler it was dispatched to never ran, and its name on a 2xx would
	 * say it answered. The rollup still counts the request under that route.
	 * An action that ran behind the same plugin is named. Both requests go
	 * through Controller::dispatch(), so the flag is the one it sets.
	 * Falsify: send the route whatever the plugins did — the refusal and the
	 * cached page carry `/news/item`; read the flag the wrong way round —
	 * the action that ran sends none.
	 */
	public function aRequestAPluginAnsweredSendsNoRoute(): bool
	{
		[$stopped, $stoppedRequest] = self::dispatchedBehindAPlugin('pre');
		[$handled, $handledRequest] = self::dispatchedBehindAPlugin(null);
		
		$context = function(Request $request, int $status): ?array
		{
			$sender = $this->makeRouteSender($this->webApp($request), $status);
			$sender->captureException(new Exception('plugin probe'));
			$sender->terminate();
			
			return self::sentContext($sender, 'plugin probe');
		};
		
		$refused = $context($stoppedRequest, 403);
		$cached = $context($stoppedRequest, 200);
		$served = $context($handledRequest, 200);
		
		return $stopped->ran === false && $stopped->isDispatched() === true
			&& $handled->ran === true && $handled->isDispatched() === false
			&& $refused !== null && array_key_exists('route', $refused) === false
			&& ($refused['status'] ?? null) === 403
			&& $cached !== null && array_key_exists('route', $cached) === false
			&& CodesafeRollup::routeFor(403, $stoppedRequest) === '/news/item'
			&& CodesafeRollup::routeFor(200, $stoppedRequest) === '/news/item'
			&& ($served['route'] ?? null) === '/news/item';
	}
	
	/**
	 * RULE: the dispatched flag is read at the shutdown flush, whoever set
	 * it: a plugin's postDispatch() after the action ran, or the action
	 * itself, leaves the route out the same way. The rollup still counts the
	 * request under the handler's route.
	 * Falsify: send the route whatever the plugins did — both carry
	 * `/news/item`; read the flag the wrong way round — both carry it too.
	 */
	public function aFlagSetAfterTheActionAlsoSendsNoRoute(): bool
	{
		[$post, $postRequest] = self::dispatchedBehindAPlugin('post');
		[$action, $actionRequest] = self::dispatchedBehindAPlugin('action');
		
		$context = function(Request $request): ?array
		{
			$sender = $this->makeRouteSender($this->webApp($request), 200);
			$sender->captureException(new Exception('late flag'));
			$sender->terminate();
			
			return self::sentContext($sender, 'late flag');
		};
		
		$afterPlugin = $context($postRequest);
		$byAction = $context($actionRequest);
		
		return $post->ran === true && $post->isDispatched() === true
			&& $action->ran === true && $action->isDispatched() === true
			&& $afterPlugin !== null && array_key_exists('route', $afterPlugin) === false
			&& ($afterPlugin['status'] ?? null) === 200
			&& $byAction !== null && array_key_exists('route', $byAction) === false
			&& CodesafeRollup::routeFor(200, $postRequest) === '/news/item'
			&& CodesafeRollup::routeFor(200, $actionRequest) === '/news/item';
	}
	
	/**
	 * RULE: the route belongs to the shutdown flush alone: once terminate()
	 * returned, a later flush on the same sender (a service a later
	 * application reuses) is mid-request again and sends no route.
	 * Falsify: keep the shutdown mark after terminate() (drop the reset in
	 * its finally) — the later flush sends `/news/item`.
	 */
	public function aFlushAfterTheShutdownSendsNoRoute(): bool
	{
		$handler = $this->sharedRequestKept(static fn(): Controller => new class extends Controller
		{
			public function item(): void
			{
			}
		});
		
		$request = new Request;
		$request->setController('news');
		$request->setAction('item');
		$request->setActionMethod('item');
		$request->setDispatchedController($handler);
		
		$sender = $this->makeRouteSender($this->webApp($request), 200);
		$sender->captureException(new Exception('at the shutdown'));
		$sender->terminate();
		$sender->captureException(new Exception('after the shutdown'));
		$sender->flush();
		
		$ended = self::sentContext($sender, 'at the shutdown');
		$after = self::sentContext($sender, 'after the shutdown');
		
		return ($ended['route'] ?? null) === '/news/item'
			&& $after !== null && array_key_exists('route', $after) === false;
	}
	
	/**
	 * RULE: a web request whose status cannot be read still names its
	 * dispatched route: no status is not a 404, so the event carries no
	 * status and the handler's route, never __unmatched.
	 * Falsify: read a missing status as a 404 — the route is __unmatched;
	 * send the route only beside a status — none is sent.
	 */
	public function aWebRequestWithoutAStatusNamesItsRoute(): bool
	{
		$handler = $this->sharedRequestKept(static fn(): Controller => new class extends Controller
		{
			public function item(): void
			{
			}
		});
		
		$request = new Request;
		$request->setController('news');
		$request->setAction('item');
		$request->setActionMethod('item');
		$request->setDispatchedController($handler);
		
		$sender = $this->makeRouteSender($this->webApp($request), null);
		$sender->captureException(new Exception('no status'));
		$sender->terminate();
		
		$context = self::sentContext($sender, 'no status');
		
		return $context !== null
			&& array_key_exists('status', $context) === false
			&& ($context['route'] ?? null) === '/news/item';
	}
	
	/**
	 * RULE: one name per request: within one flush the rollup counts the
	 * request under the route its event carries, a handler's and __unmatched
	 * alike. A probe answered 200 without the miss on record sends no route
	 * and is still counted __unmatched. Each flush counts under a cache
	 * prefix of its own, removed afterwards. Skipped without APCu.
	 * Falsify: let the rollup's observe() name the route from the request's
	 * own strings — it counts the probe as `/wp-admin/index` while the event
	 * says __unmatched; let the Sender do so — the event says it; send
	 * __unmatched on a 2xx whatever was collected — the unrecorded probe
	 * carries it.
	 */
	public function theRollupCountsTheRouteTheEventCarries(): bool
	{
		if(function_exists('apcu_enabled') === false || apcu_enabled() === false)
		{
			return true;
		}
		
		$handler = $this->sharedRequestKept(static fn(): Controller => new class extends Controller
		{
			public function item(): void
			{
			}
		});
		
		$dispatched = new Request;
		$dispatched->setController('news');
		$dispatched->setAction('item');
		$dispatched->setActionMethod('item');
		$dispatched->setDispatchedController($handler);
		
		$undispatched = new Request;
		$undispatched->setController('wp-admin');
		$undispatched->setAction('index');
		
		$flush = function(Request $request, Throwable ...$collected): array
		{
			$prefix = 'route-test-' . bin2hex(random_bytes(4));
			$sender = $this->makeRouteSender($this->webApp($request, $prefix, self::collected(...$collected)), 200, true);
			$sender->captureException(new Exception('counted probe'));
			$sender->terminate();
			
			$context = self::sentContext($sender, 'counted probe');
			
			return [$context, $context['route'] ?? null, self::countedRoutes($prefix)];
		};
		
		[, $handlerRoute, $handlerCounted] = $flush($dispatched);
		[, $probeRoute, $probeCounted] = $flush($undispatched, new NotFoundException('No controller'));
		[$unrecorded, , $unrecordedCounted] = $flush($undispatched);
		
		return $handlerRoute === '/news/item' && $handlerCounted === [$handlerRoute]
			&& $probeRoute === '__unmatched' && $probeCounted === [$probeRoute]
			&& $unrecorded !== null && array_key_exists('route', $unrecorded) === false
			&& $unrecordedCounted === ['__unmatched'];
	}
	
	/**
	 * A sender that keeps the batches it would post, its response status
	 * scripted, running in $app; null, the runner's own (a CLI run). With
	 * $rollups its flush counts the request in the traffic rollup too
	 *
	 * @return CodesafeSender&object{sent: list<array>}
	 */
	protected function makeRouteSender(
		?Application $app,
		?int $status,
		bool $rollups = false,
	): CodesafeSender
	{
		$config = new ArrayObject([
			'enabled' => true,
			'url' => 'https://console.invalid',
			'key' => 'test-key',
			'rollups' => $rollups,
			// no Shield pull from a test
			'shield' => ['kill' => true],
		]);
		
		$sender = container()->injectMissing(new class($config, $status) extends CodesafeSender
		{
			/** @var list<array> */
			public array $sent = [];
			
			public function __construct(
				ArrayObject $config,
				protected ?int $status,
			)
			{
				parent::__construct($config);
			}
			
			public function useApp(
				Application $app,
			): void
			{
				$this->app = $app;
			}
			
			protected function responseStatus(): ?int
			{
				return $this->status;
			}
			
			protected function send(
				string $json,
			): void
			{
				$this->sent[] = (array)json_decode($json, true);
			}
			
			// the daily hello and the inventory post on a web flush: never from a test
			protected function maybeHello(): void
			{
			}
			
			protected function maybeInventory(): void
			{
			}
		});
		
		if($app !== null)
		{
			$sender->useApp($app);
		}
		
		return $sender;
	}
	
	/**
	 * A web application answering $request, without booting a second one;
	 * given null, one that cannot tell its request. $cachePrefix replaces the
	 * runner's cache prefix, so its rollup keys are its own. Its Events
	 * service holds what $events collected, nothing without it: the runner's
	 * holds the runner's own
	 */
	protected function webApp(
		?Request $request,
		?string $cachePrefix = null,
		?Events $events = null,
	): Application
	{
		return new class($request, $cachePrefix, $events ?? self::collected()) extends Application
		{
			protected Services $scriptedServices;
			
			public function __construct(
				protected ?Request $answering,
				?string $cachePrefix,
				Events $events,
			)
			{
				$this->config = Application::$instance->getConfig();
				if($cachePrefix !== null)
				{
					// a copy: the runner's own config keeps its prefix
					$this->config = new ArrayObject(['cache' => ['prefix' => $cachePrefix]] + $this->config->getArrayCopy());
				}
				
				$container = new Container;
				$container->registerValue(Events::SYMBOL, $events);
				$this->scriptedServices = new Services($container);
			}
			
			public function getRequest(): Request
			{
				return $this->answering ?? throw new RuntimeException('no request');
			}
			
			public function getServices(): Services
			{
				return $this->scriptedServices;
			}
			
			public function getEnv(): string
			{
				return Application::$instance->getEnv();
			}
		};
	}
	
	/**
	 * An Events service that collected $collected; constructed without the
	 * error and exception handlers a real one installs over the runner's
	 */
	protected static function collected(
		Throwable ...$collected,
	): Events
	{
		$events = new class extends Events
		{
			public function __construct()
			{
			}
		};
		foreach($collected as $event)
		{
			$events->add($event);
		}
		
		return $events;
	}
	
	/**
	 * The context of the sent event with $message, null when none was sent
	 */
	protected static function sentContext(
		CodesafeSender $sender,
		string $message,
	): ?array
	{
		foreach($sender->sent as $batch)
		{
			foreach($batch as $payload)
			{
				if(($payload['message'] ?? null) === $message)
				{
					return (array)($payload['context'] ?? []);
				}
			}
		}
		
		return null;
	}
	
	/**
	 * What $run returns: constructing a controller points the SHARED request
	 * at it, so the runner's own controller is put back before anything else
	 * can notice
	 */
	protected function sharedRequestKept(
		callable $run,
	): mixed
	{
		$shared = $this->app->getRequest();
		$running = $shared->getControllerInstance();
		try
		{
			return $run();
		}
		finally
		{
			if($running !== null)
			{
				$shared->setControllerInstance($running);
			}
		}
	}
	
	/**
	 * A handler run through Controller::dispatch() behind one plugin, and the
	 * request dispatched to it. $marks says who sets the dispatched flag:
	 * 'pre' the plugin before the action, as the Csrf and Shield plugins and
	 * a page-cache hit do; 'post' the plugin after it; 'action' the action
	 * itself; null nobody
	 *
	 * @return array{0: Controller&object{ran: bool}, 1: Request}
	 */
	protected static function dispatchedBehindAPlugin(
		?string $marks,
	): array
	{
		$plugin = new class($marks) extends Plugin
		{
			// no container: the controller is handed over below
			public function __construct(
				protected ?string $marks,
			)
			{
			}
			
			public function preDispatch(): void
			{
				if($this->marks === 'pre')
				{
					$this->getController()->setDispatched(true);
				}
			}
			
			public function postDispatch(): void
			{
				if($this->marks === 'post')
				{
					$this->getController()->setDispatched(true);
				}
			}
		};
		
		$controller = new class($plugin, $marks) extends Controller
		{
			public bool $ran = false;
			
			// no container and none of the configured plugins, only the one given
			public function __construct(
				Plugin $plugin,
				protected ?string $marks,
			)
			{
				$this->addPlugin($plugin->setController($this), 'probe');
			}
			
			public function item(): void
			{
				$this->ran = true;
				if($this->marks === 'action')
				{
					$this->setDispatched(true);
				}
			}
		};
		$controller->dispatch('item');
		
		$request = new Request;
		$request->setController('news');
		$request->setAction('item');
		$request->setActionMethod('item');
		$request->setDispatchedController($controller);
		
		return [$controller, $request];
	}
	
	/**
	 * The routes the rollup counted under $cachePrefix; every key under it is
	 * removed after
	 *
	 * @return list<string>
	 */
	protected static function countedRoutes(
		string $cachePrefix,
	): array
	{
		$keys = '~^' . preg_quote(CodesafeRollup::keyPrefix($cachePrefix), '~') . '\d+:r:(.+)$~';
		$routes = [];
		foreach(new APCUIterator($keys) as $entry)
		{
			if(preg_match($keys, (string)$entry['key'], $match) === 1)
			{
				$routes[] = $match[1];
			}
		}
		apcu_delete(new APCUIterator('~^' . preg_quote($cachePrefix, '~') . '~'));
		
		return $routes;
	}
	
	/**
	 * A sender whose finished batch a test can read, its application
	 * swappable for a web one
	 *
	 * @return CodesafeSender&object{batch: callable(): array, useApp: callable(Application): void}
	 */
	protected function makeBatchSender(): CodesafeSender
	{
		$config = new ArrayObject([
			'enabled' => true,
			'url' => 'https://console.invalid',
			'key' => 'test-key',
		]);
		
		return container()->injectMissing(new class($config) extends CodesafeSender
		{
			public function batch(): array
			{
				return $this->buildBatch();
			}
			
			public function useApp(
				Application $app,
			): void
			{
				$this->app = $app;
			}
		});
	}
}
