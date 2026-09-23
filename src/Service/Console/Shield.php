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
use Throwable;

use function class_exists;
use function file_get_contents;
use function hash;
use function is_array;
use function is_dir;
use function mkdir;
use function rtrim;
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
	
	protected ?Kernel $kernel = null;
	
	protected ?Closure $http;
	
	protected ?Closure $report;
	
	/**
	 * @param ?ArrayObject $config the `console` config block
	 * @param ?string $prefix the install's cache prefix (Sender::cachePrefix)
	 * @param ?callable $http a transport for tests; null = the kernel's own
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
			$this->http,
			$this->report,
		);
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
