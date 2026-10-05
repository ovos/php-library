<?php
declare(strict_types=1);

namespace Tests\Service\Codesafe;

use ErrorException;
use Exception;
use Ovos\Application;
use Ovos\ArrayObject;
use Ovos\Cache\Prefixer;
use Ovos\Exception\ForbiddenException;
use Ovos\Exception\NotFoundException;
use Ovos\Exception\Priority;
use Ovos\Service\Codesafe\Otlp;
use Ovos\Service\Codesafe\Sender as CodesafeSender;
use Ovos\Service\Codesafe\Untracked;
use Ovos\Service\Logger;
use Ovos\Services;
use Ovos\Test;
use Ovos\Test\Exception\SkipException;
use Throwable;
use WeakReference;

use function Ovos\container;

use function apcu_delete;
use function apcu_enabled;
use function array_filter;
use function array_key_exists;
use function array_push;
use function count;
use function function_exists;
use function json_decode;
use function json_encode;
use function gc_collect_cycles;
use function in_array;
use function mb_strlen;
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
	 * two only where the kernel runs, and nothing at all from a sender that is
	 * off
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
		
		return $on === [
				'v' => 1,
				'client' => 'php-library/8.5.41',
				'features' => [
					'errors' => true,
					'not_found' => true,
					'rollups' => false,
					'files' => true,
					'shield_detect' => true,
					'shield_enforce' => true,
				],
			]
			&& array_key_exists('security', $on['features']) === false
			&& $killed['features']['shield_detect'] === false
			&& $killed['features']['shield_enforce'] === false
			&& $killed['features']['files'] === false
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
	
	public function notFoundExceptionStaysAnErrorWhenDisabled(): bool
	{
		$sender = $this->makeSender(report404: false);
		
		$sender->mergeEvent(new NotFoundException('No route'));
		$payload = $sender->lastPayload();
		
		// a normal exception payload: no 404 type override, no kind yet (flush
		// stamps `error` beside runtime/entry), error priority
		return ($payload['type'] ?? null) === null
			&& ($payload['kind'] ?? null) === null
			&& ($payload['priority'] ?? null) === Priority::ERROR;
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
	 * header, and shipping the credential inside a body whole.
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
			
			protected function allowSecurity(): bool
			{
				return $this->securityAllowed;
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
