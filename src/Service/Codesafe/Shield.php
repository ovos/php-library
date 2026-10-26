<?php
declare(strict_types=1);

namespace Ovos\Service\Codesafe;

use Closure;
use Ovos\Application;
use Ovos\ArrayObject;
use Ovos\Cache\Prefixer;
use Ovos\Client;
use Ovos\Codesafe\Shield\Consent;
use Ovos\Codesafe\Shield\Facts;
use Ovos\Codesafe\Shield\Kernel;
use Ovos\Codesafe\Shield\Platform;
use Ovos\Codesafe\Shield\Store;
use Ovos\Codesafe\Shield\Verdict;
use Ovos\Codesafe\Shield\Wordpress;
use Ovos\Service\Auth;
use Ovos\Stream\Request;
use Throwable;

use function array_values;
use function class_exists;
use function explode;
use function file_get_contents;
use function hash;
use function is_array;
use function is_dir;
use function is_scalar;
use function is_string;
use function max;
use function mkdir;
use function rtrim;
use function str_contains;
use function str_starts_with;
use function strtolower;
use function substr;
use function sys_get_temp_dir;
use function trim;

// the kernel is one vendored multi-class file (ovos/codesafe client-php/Shield.php,
// byte-identical here); a plain class binds at compile time, so the guard has
// to sit at the require, never inside the file. A platform module is required
// after it, and only when the config names it (platforms())
if(class_exists(Kernel::class, false) === false)
{
	require_once __DIR__ . '/../../Codesafe/Shield/Kernel.php';
}

/**
 * The Shield in a php-library application — the adapter around the
 * codesafe's request-side kernel (Ovos\Codesafe\Shield\Kernel, vendored as
 * src/Codesafe/Shield/Kernel.php; ovos/codesafe docs/SENDER.md §10). The
 * kernel pulls this project's LIVE rules from the codesafe instance it already
 * reports to, judges every request against them and reports a match as a
 * `shield_observe` / `shield_block` security event; this class reads the
 * three consent switches and the store's place from `codesafe.shield.*`,
 * builds the Facts off the request, hands a match to the Sender and pulls
 * on the Sender's shutdown tick. The controller plugin
 * Ovos\Plugins\Codesafe\Shield turns `block` into the application's 403.
 *
 * The pull travels over the framework's own HTTP client (Ovos\Stream\Request:
 * the timeout on both phases, redirects off, a response cap, best-effort —
 * a codesafe instance that does not answer is a null response, never a warning in
 * the host's log) unless a transport was injected; the kernel's own curl
 * stays the default for a host with nothing better (MG 2026-09-23).
 *
 *   codesafe:
 *     shield:
 *       detect: yes   # pull the rules and OBSERVE every request: a match is reported, nothing is blocked
 *       enforce: yes  # answer 403 to a request a PROVEN rule matches — inert without detect
 *       kill: no      # off entirely, no network — the switch that needs no instance
 *       dir: ''       # where shield.json (the durable tier) lives; '' = the system temp dir
 *       platforms: [] # the kernel's platform modules (MODULES), e.g. [wordpress]; none = the core alone
 *       rest_prefix: '' # WordPress's REST prefix (rest_get_url_prefix()) for its module; '' = wp-json
 *
 * detect and enforce are on unless set to no (codesafe docs/plans/shield-auto-deploy.md
 * D8): a site is protected without anyone opting in, and codesafe — the
 * project's Shield off, a rule switched off for it — is where it is stopped.
 *
 * The kernel runs its platform-neutral core alone unless `platforms` names a
 * module: an application is no WordPress, and a rule in WordPress's words (a
 * REST route under /wp-json, a shortcode or block attribute) is left out at
 * the pull and never read. A host in front of WordPress names `wordpress`;
 * the kernel and the request's Facts are then given its module, and the pull
 * says so in X-Shield-Platforms, which codesafe serves by. In front of a
 * WordPress whose REST prefix is not wp-json, `rest_prefix` names it: the
 * module reads a `rest` route under that prefix alone.
 *
 * Fails open on everything, like the kernel. A rule exists only while a
 * live CVE finding on this project justifies it, or a person wrote it with
 * an expiry or a name — codesafe lifts the rest by itself.
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Shield
{
	/** the install's APCu namespace, beneath the configured cache prefix like the rollups */
	public const string PREFIX = 'ovos:codesafe:shield:';
	
	/** the temp-dir folder the durable tier falls back to */
	public const string DIR = 'ovos-codesafe-shield';
	
	/** the most a pull reads: the door caps a payload at 25 rules of 600 bytes — a megabyte is a wrong door */
	public const int RESPONSE_LIMIT = 1_048_576;
	
	/** the rollup field prefix of each outcome the kernel store counts: the verdicts, then a vpatch's evidence (Kernel::tallied()) */
	public const array HIT_FIELDS = ['so:' => 'observe', 'sb:' => 'block', 'se:' => 'entry', 'sm:' => 'match', 'sa:' => 'authed'];
	
	/**
	 * The platform modules `codesafe.shield.platforms` may name, by the word
	 * X-Shield-Platforms names each by, with the vendored file beside
	 * Kernel.php that declares it
	 */
	public const array MODULES = ['wordpress' => [Wordpress::class, 'Wordpress.php']];
	
	protected ?Kernel $kernel = null;
	
	/** @var ?list<Platform> the modules the config names, built once */
	protected ?array $platforms = null;
	
	protected ?Closure $http;
	
	protected ?Closure $report;
	
	/**
	 * @param ?ArrayObject $config the `codesafe` config block (Sender::configOf)
	 * @param ?string $prefix the install's cache prefix (Sender::cachePrefix)
	 * @param ?callable $http a transport for tests; null = the framework's stream client (transport())
	 * @param ?callable $report where a match goes — the Sender's reportRefusal; null = counted, not reported
	 */
	public function __construct(
		protected ?ArrayObject $config,
		protected ?string $prefix = null,
		?callable $http = null,
		?callable $report = null,
	)
	{
		$this->http = $http === null ? null : Closure::fromCallable($http);
		$this->report = $report === null ? null : Closure::fromCallable($report);
	}
	
	/**
	 * The Rollup's transport contract — enabled, url and key — plus
	 * detect on and kill off. Without it nothing runs and nothing is pulled
	 */
	public function isEnabled(): bool
	{
		if($this->config === null || $this->config->enabled !== true
			|| (string)$this->config->url === '' || (string)$this->config->key === '')
		{
			return false;
		}
		
		return $this->consent()->active();
	}
	
	/**
	 * The three switches, read from the config on every call — never cached in
	 * the ruleset. detect and enforce default to on: absent is consent, an
	 * explicit no (or anything but true) is not
	 */
	public function consent(): Consent
	{
		$shield = $this->shieldConfig();
		
		return new Consent(
			($shield['detect'] ?? true) === true,
			($shield['enforce'] ?? true) === true,
			($shield['kill'] ?? null) === true,
		);
	}
	
	/**
	 * codesafe's answer said the project's ruleset version (an ingest answer's
	 * `shield`, codesafe docs/plans/shield-auto-deploy.md P7.d): the kernel
	 * forces its next pull when it differs. Nothing while the shield is off
	 */
	public function nudge(
		string $version,
	): bool
	{
		try
		{
			return $this->isEnabled() && $this->kernel()->nudge($version);
		}
		catch(Throwable)
		{
			return false;
		}
	}
	
	/** this install's APCu key prefix, through the same Prefixer the cache stores use */
	public function keyPrefix(): string
	{
		return (new Prefixer($this->prefix !== '' ? $this->prefix : null))->prefix(self::PREFIX);
	}
	
	/**
	 * The durable tier: `codesafe.shield.dir`, or the system temp dir's own
	 * folder; the file is named by the install prefix and the key, so two
	 * installs on one box never read each other's rules
	 */
	public function file(): string
	{
		$dir = trim((string)($this->shieldConfig()['dir'] ?? ''));
		if($dir === '')
		{
			$dir = rtrim(sys_get_temp_dir(), '/\\') . '/' . self::DIR;
		}
		if(is_dir($dir) === false)
		{
			@mkdir($dir, 0700, true);
		}
		
		return rtrim($dir, '/\\') . '/shield-' . substr(hash('sha256', $this->keyPrefix() . '|' . (string)$this->config?->key), 0, 16) . '.json';
	}
	
	public function kernel(): Kernel
	{
		return $this->kernel ??= new Kernel(
			(string)$this->config?->url,
			(string)$this->config?->key,
			new Store($this->file(), $this->keyPrefix()),
			$this->http ?? $this->transport(...),
			$this->report,
			platforms: $this->platforms(),
		);
	}
	
	/**
	 * The platform modules `codesafe.shield.platforms` names — a list or a
	 * comma list, any case; a word MODULES does not know is ignored — each
	 * required after the kernel the first time: none unless named, so an
	 * application runs the core alone
	 *
	 * @return list<Platform>
	 */
	public function platforms(): array
	{
		if($this->platforms !== null)
		{
			return $this->platforms;
		}
		$named = $this->shieldConfig()['platforms'] ?? [];
		if($named instanceof ArrayObject)
		{
			$named = $named->getArrayCopy();
		}
		$words = is_string($named) ? explode(',', $named) : (is_array($named) ? $named : []);
		$platforms = [];
		foreach($words as $word)
		{
			$module = is_string($word) ? (self::MODULES[strtolower(trim($word))] ?? null) : null;
			if($module === null || isset($platforms[$module[0]]))
			{
				continue;
			}
			// by path behind its guard, as the kernel: autoloaded before Kernel.php, its interface would not be found
			if(class_exists($module[0], false) === false)
			{
				require_once __DIR__ . '/../../Codesafe/Shield/' . $module[1];
			}
			$platforms[$module[0]] = new $module[0];
		}
		
		return $this->platforms = array_values($platforms);
	}
	
	/**
	 * The kernel's transport contract over Ovos\Stream\Request — GET, the
	 * kernel's headers and user agent, the timeout on the connection and the
	 * read alike, no redirect (the door answers where it is asked), the body
	 * capped, best-effort: a failure of any kind is `status 0`, which the
	 * kernel reads as `failed` and keeps the rules it has. Never throws
	 *
	 * @param list<string> $headers `Name: value` lines
	 * @return array{status: int, headers: array<string, string>, body: string}
	 */
	public function transport(
		string $url,
		array $headers,
		int $timeoutMs,
	): array
	{
		try
		{
			$request = (new Request($url))
				->setMethod(Request::METHOD_GET)
				->setTimeout(max(1, $timeoutMs) / 1000)
				->setIgnoreErrors()
				->setFollowRedirects(0)
				->setBestEffort()
				->setResponseLimit(self::RESPONSE_LIMIT)
				->setUserAgent(Kernel::USER_AGENT);
			foreach($headers as $header)
			{
				$request->addHeader((string)$header);
			}
			$request->invoke();
			$status = $request->getResponseStatusCode();
			if($status === null)
			{
				return ['status' => 0, 'headers' => [], 'body' => ''];
			}
			
			return ['status' => $status, 'headers' => self::headersOf($request->getResponseMetaData()), 'body' => (string)$request->getResponse()];
		}
		catch(Throwable)
		{
			return ['status' => 0, 'headers' => [], 'body' => ''];
		}
	}
	
	/**
	 * The response headers as the kernel reads them — lowercase names, the
	 * last hop's values — off the stream's meta data (`wrapper_data`: one
	 * status line per hop, then the headers)
	 *
	 * @return array<string, string>
	 */
	public static function headersOf(
		?array $meta,
	): array
	{
		$headers = [];
		foreach((array)($meta['wrapper_data'] ?? []) as $line)
		{
			if(is_string($line) === false)
			{
				continue;
			}
			if(str_starts_with($line, 'HTTP/'))
			{
				// a new hop: what the hop before said no longer applies
				$headers = [];
				
				continue;
			}
			if(str_contains($line, ':'))
			{
				[$name, $value] = explode(':', $line, 2);
				$headers[strtolower(trim($name))] = trim($value);
			}
		}
		
		return $headers;
	}
	
	/** the request as the kernel reads it — the adapter reads the superglobals, the kernel never does */
	public function facts(
		?Application $app = null,
	): Facts
	{
		// the parsed bags a virtual patch reads; no `can`: an app's roles are not the
		// capabilities a vpatch names, so a signed-in request to a `lacks:` rule observes.
		// Read with the kernel's modules, in whose words its rules were kept
		return Facts::fromServer(
			$_SERVER,
			$_POST,
			static fn(): string => (string)file_get_contents('php://input'),
			self::isAuthed($app) === true,
			// the visitor's address as the app reads it: behind a configured
			// proxy the forwarded chain walked right to left, never a header a
			// peer that is no proxy wrote
			ip: Client::getIp(),
			user: self::identity($app),
			get: $_GET,
			cookies: $_COOKIE,
			files: $_FILES,
			platforms: $this->platforms(),
			options: $this->options(),
		);
	}
	
	/**
	 * What the kernel's modules read of the host, by each module's own key:
	 * WordPress's REST prefix from `codesafe.shield.rest_prefix`. None when it
	 * is unset or WordPress's module is not named, and the module reads wp-json
	 *
	 * @return array<string, string>
	 */
	public function options(): array
	{
		$prefix = $this->shieldConfig()['rest_prefix'] ?? '';
		// without its slashes, as WordPress's plugin hands it over
		$prefix = is_string($prefix) ? trim(trim($prefix), '/') : '';
		if($prefix === '')
		{
			return [];
		}
		foreach($this->platforms() as $platform)
		{
			// the module's own constant only once it is loaded: unnamed, nothing required it
			if($platform instanceof Wordpress)
			{
				return [Wordpress::OPTION_REST_PREFIX => $prefix];
			}
		}
		
		return [];
	}
	
	/** judge, count, report — `pass` with the word `off` when the shield is not enabled */
	public function judge(
		Facts $facts,
	): Verdict
	{
		if($this->isEnabled() === false)
		{
			return Verdict::pass('off');
		}
		
		return $this->kernel()->handle($facts, $this->consent());
	}
	
	/**
	 * The rules' hits of one minute as rollup fields — `so:<id>` observed,
	 * `sb:<id>` blocked, and a vpatch's evidence: `se:<id>` reached,
	 * `sm:<id>` matched, `sa:<id>` matched signed in — taken from the kernel
	 * store (APCu; once), for the Rollup's flush hook. Nothing while the
	 * shield is not enabled
	 *
	 * @return array<string, int>
	 */
	public function hits(
		int $minute,
	): array
	{
		if($this->isEnabled() === false)
		{
			return [];
		}
		$fields = [];
		foreach($this->kernel()->store()->takeHits($minute) as $id => $counts)
		{
			foreach(self::HIT_FIELDS as $prefix => $outcome)
			{
				if(($counts[$outcome] ?? 0) > 0)
				{
					$fields[$prefix . $id] = $counts[$outcome];
				}
			}
		}
		
		return $fields;
	}
	
	/** the conditional GET on the host's tick; `off` when the shield is not enabled */
	public function pull(): string
	{
		if($this->isEnabled() === false)
		{
			return Kernel::PULL_OFF;
		}
		
		return $this->kernel()->pull($this->consent());
	}
	
	/**
	 * Whether the request is a signed-in one — the way the Rollup reads it:
	 * the Auth service, only if something already resolved it; null when the
	 * application has no such notion
	 */
	public static function isAuthed(
		?Application $app,
	): ?bool
	{
		if($app === null)
		{
			return null;
		}
		try
		{
			$container = $app->getContainer();
			if($container->isResolved(Auth::SYMBOL) === false)
			{
				return null;
			}
			$auth = $container->resolve(Auth::SYMBOL);
			
			return $auth instanceof Auth ? $auth->getUser() !== null : null;
		}
		catch(Throwable)
		{
			return null;
		}
	}
	
	/**
	 * Who a `user`-keyed rate rule counts: the signed-in user's id, '' when
	 * nobody is signed in or the app has no Auth — the rule is then skipped
	 * for the request, never charged to one shared anonymous bucket
	 */
	public static function identity(
		?Application $app,
	): string
	{
		if($app === null)
		{
			return '';
		}
		try
		{
			$container = $app->getContainer();
			if($container->isResolved(Auth::SYMBOL) === false)
			{
				return '';
			}
			$auth = $container->resolve(Auth::SYMBOL);
			$user = $auth instanceof Auth ? $auth->getUser() : null;
			$id = $user?->id;
			
			return is_scalar($id) ? (string)$id : '';
		}
		catch(Throwable)
		{
			return '';
		}
	}
	
	/** the `shield` block as a plain array, whatever the config object hands back */
	protected function shieldConfig(): array
	{
		$shield = $this->config?->shield;
		if($shield instanceof ArrayObject)
		{
			$shield = $shield->getArrayCopy();
		}
		
		return is_array($shield) ? $shield : [];
	}
}
