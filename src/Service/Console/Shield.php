<?php
declare(strict_types=1);

namespace Ovos\Service\Console;

use Closure;
use Ovos\Application;
use Ovos\ArrayObject;
use Ovos\Cache\Prefixer;
use Ovos\Console\Shield\Consent;
use Ovos\Console\Shield\Facts;
use Ovos\Console\Shield\Kernel;
use Ovos\Console\Shield\Store;
use Ovos\Console\Shield\Verdict;
use Ovos\Service\Auth;
use Ovos\Stream\Request;
use Throwable;

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

// the kernel is one vendored multi-class file (ovos/console client-php/Shield.php,
// byte-identical here); a plain class binds at compile time, so the guard has
// to sit at the require, never inside the file
if(class_exists(Kernel::class, false) === false)
{
	require_once __DIR__ . '/../../Console/Shield/Kernel.php';
}

/**
 * The Shield in a php-library application — the adapter around the
 * console's request-side kernel (Ovos\Console\Shield\Kernel, vendored as
 * src/Console/Shield/Kernel.php; ovos/console docs/SENDER.md §10). The
 * kernel pulls this project's LIVE rules from the console it already
 * reports to, judges every request against them and reports a match as a
 * `shield_observe` / `shield_block` security event; this class reads the
 * three consent switches and the store's place from `console.shield.*`,
 * builds the Facts off the request, hands a match to the Sender and pulls
 * on the Sender's shutdown tick. The controller plugin
 * Ovos\Plugins\Console\Shield turns `block` into the application's 403.
 *
 * The pull travels over the framework's own HTTP client (Ovos\Stream\Request:
 * the timeout on both phases, redirects off, a response cap, best-effort —
 * a console that does not answer is a null response, never a warning in
 * the host's log) unless a transport was injected; the kernel's own curl
 * stays the default for a host with nothing better (MG 2026-09-23).
 *
 *   console:
 *     shield:
 *       detect: no    # pull the rules and OBSERVE every request: a match is reported, nothing is blocked
 *       enforce: no   # answer 403 to a request a PROVEN rule matches — inert without detect
 *       kill: no      # off entirely, no network — the switch that needs no console
 *       dir: ''       # where shield.json (the durable tier) lives; '' = the system temp dir
 *
 * Fails open on everything, like the kernel. A rule exists only while a
 * live CVE finding on this project justifies it, or a person wrote it with
 * an expiry or a name — the console lifts the rest by itself.
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Shield
{
	/** the install's APCu namespace, beneath the configured cache prefix like the rollups */
	public const string PREFIX = 'ovos:console:shield:';
	
	/** the temp-dir folder the durable tier falls back to */
	public const string DIR = 'ovos-console-shield';
	
	/** the most a pull reads: the door caps a payload at 25 rules of 600 bytes — a megabyte is a wrong door */
	public const int RESPONSE_LIMIT = 1_048_576;
	
	protected ?Kernel $kernel = null;
	
	protected ?Closure $http;
	
	protected ?Closure $report;
	
	/**
	 * @param ?ArrayObject $config the `console` config block
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
	 * The Rollup's transport contract — console enabled, url and key — plus
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
	
	/** the three switches, read from the config on every call — never cached in the ruleset */
	public function consent(): Consent
	{
		$shield = $this->shieldConfig();
		
		return new Consent(
			($shield['detect'] ?? null) === true,
			($shield['enforce'] ?? null) === true,
			($shield['kill'] ?? null) === true,
		);
	}
	
	/** this install's APCu key prefix, through the same Prefixer the cache stores use */
	public function keyPrefix(): string
	{
		return (new Prefixer($this->prefix !== '' ? $this->prefix : null))->prefix(self::PREFIX);
	}
	
	/**
	 * The durable tier: `console.shield.dir`, or the system temp dir's own
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
		);
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
		return Facts::fromServer(
			$_SERVER,
			$_POST,
			static fn(): string => (string)file_get_contents('php://input'),
			self::isAuthed($app) === true,
			user: self::identity($app),
		);
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
	 * `sb:<id>` blocked — taken from the kernel store (APCu; once), for the
	 * Rollup's flush hook. Nothing while the shield is not enabled
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
			if(($counts['observe'] ?? 0) > 0)
			{
				$fields['so:' . $id] = (int)$counts['observe'];
			}
			if(($counts['block'] ?? 0) > 0)
			{
				$fields['sb:' . $id] = (int)$counts['block'];
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
