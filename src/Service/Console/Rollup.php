<?php
declare(strict_types=1);

namespace Ovos\Service\Console;

use APCUIterator;
use Ovos\Application;
use Ovos\ArrayObject;
use Ovos\Cache\Prefixer;
use Ovos\Controller;
use Ovos\Request;
use Ovos\Service\Auth;
use Throwable;

use function apcu_add;
use function array_fill;
use function apcu_delete;
use function apcu_enabled;
use function apcu_entry;
use function apcu_fetch;
use function apcu_inc;
use function apcu_store;
use function base_convert;
use function crc32;
use function curl_exec;
use function curl_init;
use function curl_setopt_array;
use function function_exists;
use function getmypid;
use function gethostname;
use function headers_list;
use function http_response_code;
use function in_array;
use function intdiv;
use function is_float;
use function is_int;
use function is_string;
use function microtime;
use function json_encode;
use function ksort;
use function preg_match;
use function preg_quote;
use function preg_replace;
use function rtrim;
use function str_contains;
use function strrpos;
use function strtolower;
use function substr;
use function time;

use const CURLOPT_CONNECTTIMEOUT_MS;
use const CURLOPT_HTTPHEADER;
use const CURLOPT_NOSIGNAL;
use const CURLOPT_POST;
use const CURLOPT_POSTFIELDS;
use const CURLOPT_RETURNTRANSFER;
use const CURLOPT_TIMEOUT_MS;
use const JSON_INVALID_UTF8_SUBSTITUTE;
use const JSON_PARTIAL_OUTPUT_ON_ERROR;

/**
 * Per-minute traffic rollup accumulator — the console's DENOMINATOR layer,
 * client side. The error stream tells the console what broke; this tells it
 * how much traffic there was, so "379 requests for nothing we serve" can be
 * read as a rate instead of a raw count.
 *
 * One apcu_inc() set per request (sub-microsecond, no I/O), and a single
 * POST to /api/v1/ingest/rollup by whichever request first crosses a minute
 * boundary — guarded by an APCu add()-lock so only one flushes. PHP is
 * shared-nothing; APCu is the only per-pool memory a request can reach,
 * which also means:
 *
 * - NO APCu, NO ROLLUPS. A missing or disabled extension degrades to a
 *   silent no-op — this must never be the reason an app errors or slows.
 * - APCu is per FPM POOL, not per host. Several pools on one box each
 *   flush their own fragment for the same minute; the console SUMS them,
 *   and dedups retries by (instance, seq) — both APCu-held, regenerated
 *   together on an APCu restart so a recycled pid can never collide with
 *   a seq history it does not own.
 *
 * - ONE POOL, SEVERAL INSTALLS: a pool can serve more than one app, so
 *   every key carries the install's configured cache prefix in front of
 *   PREFIX — the namespace the cache stores key by. Without it the
 *   installs sum each other's counters, share one flush watermark and,
 *   worst, ship as the same (instance, seq) pair, which the console
 *   dedups against each other.
 *
 * THE CLOSED-VOCABULARY RULE (the console refuses violations wholesale):
 * every dimension comes from the app, never from the request. The route is
 * the RESOLVED controller/action — resolveActionMethod() re-proves it names
 * real code — and a request the router did not match increments only
 * __unmatched, which is exactly the probe signal the console wants. A raw
 * URI must never reach a field name.
 *
 * Opt-in twice: console.rollups here (default off), rollups_enabled on the
 * console project there. A sender deployed before the server switch is
 * flipped is refused at the endpoint and writes nothing — inert, not wrong.
 *
 * Wired from Sender::flush(), so it rides the same shutdown hook as error
 * reporting and inherits its contract: every failure is swallowed.
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Rollup
{
	/**
	 * The SHARED part of every APCu key - never a key on its own: APCu
	 * belongs to the whole FPM pool and a pool can serve several installs,
	 * so the install's cache prefix goes in front of it (see $keyPrefix)
	 */
	public const string PREFIX = 'ovos:console:rollups:';
	
	/**
	 * Key suffixes, appended to $keyPrefix by key()
	 */
	protected const string LOCK_KEY = 'lock';
	
	protected const string SEQ_KEY = 'seq';
	
	protected const string INSTANCE_KEY = 'instance';
	
	protected const string FLUSHED_KEY = 'flushed';
	
	/**
	 * Orphaned counters (a pool that stops receiving traffic mid-minute)
	 * age out on their own — well past the console's ±90min skew window,
	 * inside which they could still have shipped
	 */
	protected const int COUNTER_TTL = 3600;
	
	/**
	 * POSTs per flush — a pool waking from a long idle ships its backlog
	 * over a few requests instead of stalling one on many sends
	 */
	protected const int FLUSH_MAX = 5;
	
	/**
	 * The console rejects fragments older than its skew window — a minute
	 * this stale is deleted instead of shipped
	 */
	protected const int SKEW_MINUTES = 90;
	
	/**
	 * The verbs worth a per-method counter (the console's vocabulary);
	 * anything else still counts into requests
	 */
	public const array METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'];
	
	/**
	 * Duration histogram bounds, MILLISECONDS — a WIRE CONTRACT shared
	 * verbatim with every sender and the console's Console\Stats\Durations:
	 * bucket i counts durations > bounds[i-1] and <= bounds[i]; the 12th
	 * bucket is everything past the last bound. Fixed for the life of the
	 * feature — changing it breaks additivity across time.
	 */
	public const array DURATION_BOUNDS = [25, 50, 100, 200, 400, 800, 1600, 3200, 6400, 12800, 30000];
	
	public const int DURATION_BUCKETS = 12;
	
	/**
	 * Every APCu key this install owns starts with it:
	 * '<cache prefix>:ovos:console:rollups:'
	 */
	protected readonly string $keyPrefix;
	
	/**
	 * $prefix is the install's configured cache prefix (cache.prefix), the
	 * one the cache stores key by — the deployment's own answer to which of
	 * the installs sharing this pool is writing. Sender passes it; a null
	 * or empty one leaves the keys unnamespaced, as they were before.
	 */
	public function __construct(
		protected ?ArrayObject $config,
		?string $prefix = null,
	)
	{
		$this->keyPrefix = self::keyPrefix($prefix);
	}
	
	/**
	 * The install's namespace in front of the shared PREFIX, joined by
	 * Ovos\Cache\Prefixer — the same class, over the same configured
	 * prefix, that the cache stores build their keys with
	 */
	public static function keyPrefix(
		?string $prefix = null,
	): string
	{
		return (new Prefixer($prefix !== '' ? $prefix : null))
			->prefix(self::PREFIX);
	}
	
	/**
	 * This install's full APCu key prefix
	 */
	public function getPrefix(): string
	{
		return $this->keyPrefix;
	}
	
	/**
	 * One of this install's APCu keys, from its suffix
	 */
	protected function key(
		string $suffix,
	): string
	{
		return $this->keyPrefix . $suffix;
	}
	
	/**
	 * Rollups need the console's direct transport (url + key) AND the
	 * explicit console.rollups opt-in. An OTLP-only sender exports
	 * console.rollup.requests through its collector instead — this class
	 * never speaks OTLP.
	 */
	public function isEnabled(): bool
	{
		if($this->config === null
			|| $this->config->enabled !== true
			|| $this->config->rollups !== true)
		{
			return false;
		}
		
		return (string)$this->config->url !== ''
			&& (string)$this->config->key !== '';
	}
	
	/**
	 * Counts this request and flushes complete minutes when a boundary was
	 * crossed. Called once per request from Sender::flush(); CLI runs are
	 * not HTTP traffic and count nothing.
	 */
	public function observe(
		Application $app,
	): void
	{
		if($this->isEnabled() === false
			|| $app->isInterfaceCli()
			|| self::hasApcu() === false)
		{
			return;
		}
		
		$minute = intdiv(time(), 60);
		$status = http_response_code();
		
		// request wall time: SAPI start to this shutdown observer — framework
		// boot included, web server and network excluded. No start marker
		// means no histogram entry; a wrong duration is worse than none.
		$started = $_SERVER['REQUEST_TIME_FLOAT'] ?? null;
		$duration = is_float($started) || (is_string($started) && $started !== '')
			? (microtime(true) - (float)$started) * 1000
			: null;
		
		// a streaming response (server-sent events) is held open for as long
		// as the client listens: its wall time measures the subscription, not
		// the work. It still counts as a request - it just has no duration
		if(self::isStream(headers_list()))
		{
			$duration = null;
		}
		
		$this->count(
			$minute,
			$status,
			(string)($_SERVER['REQUEST_METHOD'] ?? ''),
			self::routeFor($status, $app->getRequest()),
			$this->isAuthed($app),
			$duration !== null && $duration >= 0 ? $duration : null,
		);
		
		$this->maybeFlush($minute);
	}
	
	/**
	 * Whether the response being finished is a stream: a Content-Type of
	 * text/event-stream, whatever its case or charset suffix. A stream is held
	 * open for as long as the client listens, so its wall time measures the
	 * subscription, not the work - observe() counts it as a request and gives
	 * it no duration. Takes the header list (headers_list() at shutdown, empty
	 * on the CLI) so the rule is testable where no headers exist.
	 */
	public static function isStream(
		array $headers,
	): bool
	{
		foreach($headers as $header)
		{
			if(preg_match('~^content-type:\s*text/event-stream\b~i', (string)$header) === 1)
			{
				return true;
			}
		}
		
		return false;
	}
	
	/**
	 * A request answered 404 matched nothing this app serves, whatever the
	 * request object claims: the error-page forward that RENDERS the 404
	 * marks the request with the error route's own controller/action, which
	 * would disguise every router miss — the exact traffic __unmatched
	 * exists to count — as a legitimate route.
	 */
	public static function routeFor(
		int|false $status,
		Request $request,
	): string
	{
		return $status === 404 ? '__unmatched' : self::routeOf($request);
	}
	
	/**
	 * The matched route pattern, or __unmatched. The controller/action
	 * strings on the Request are ROUTER INPUT — on a 404 they carry whatever
	 * the client asked for — so they only become a field name once both
	 * halves demonstrably name real code: a constructed controller instance,
	 * and an action resolveActionMethod() accepts on it. That is the same
	 * test the dispatcher applies, re-run.
	 */
	public static function routeOf(
		Request $request,
	): string
	{
		// the getter's return type is non-nullable, so a request that never
		// dispatched answers with a TypeError rather than a null — either
		// way, no constructed controller means the router matched nothing
		try
		{
			$instance = $request->getControllerInstance();
		}
		catch(Throwable)
		{
			return '__unmatched';
		}
		
		if(Controller::resolveActionMethod(
			$instance::class, $request->getActionMethod()) === null)
		{
			return '__unmatched';
		}
		
		return self::routeName($request->getController(), $request->getAction());
	}
	
	/**
	 * '/controller/action', lowercased, held to the console's route shape —
	 * a name the shape refuses collapses to __other rather than shipping
	 * anything doubtful into a field name
	 */
	public static function routeName(
		string $controller,
		string $action,
	): string
	{
		if($controller === '' || $action === '')
		{
			return '__other';
		}
		
		$route = '/' . strtolower($controller) . '/' . strtolower($action);
		
		if(preg_match('~^/[!-\~]{1,199}$~', $route) !== 1
			|| str_contains($route, '?')
			|| str_contains($route, '%'))
		{
			return '__other';
		}
		
		return $route;
	}
	
	/**
	 * A minute's collected counters as the fragment body the console
	 * expects — requests plus the status/methods/routes/authed breakdowns.
	 * Pure, so the mapping is testable without APCu.
	 *
	 * @param array<string, int> $fields flattened counter fields
	 *   ('requests', 's:200', 'm:GET', 'r:/x/y', 'a:yes')
	 */
	/**
	 * The histogram bucket one duration falls into: first bound >= value,
	 * else the overflow bucket
	 */
	public static function bucketFor(
		float $ms,
	): int
	{
		foreach(self::DURATION_BOUNDS as $i => $bound)
		{
			if($ms <= $bound)
			{
				return $i;
			}
		}
		
		return self::DURATION_BUCKETS - 1;
	}
	
	public static function assemble(
		int $minute,
		array $fields,
	): array
	{
		$payload = [
			'minute' => $minute,
			'requests' => 0,
			'status' => [],
			'methods' => [],
			'routes' => [],
			'authed' => [],
		];
		
		ksort($fields);
		
		$durations = [];
		
		foreach($fields as $field => $count)
		{
			$field = (string)$field;
			$count = (int)$count;
			
			if($field === 'requests')
			{
				$payload['requests'] = $count;
			}
			elseif(substr($field, 0, 2) === 's:')
			{
				$payload['status'][substr($field, 2)] = $count;
			}
			elseif(substr($field, 0, 2) === 'm:')
			{
				$payload['methods'][substr($field, 2)] = $count;
			}
			elseif(substr($field, 0, 3) === 'dt:')
			{
				$bucket = (int)substr($field, 3);
				$durations['__total'] ??= array_fill(0, self::DURATION_BUCKETS, 0);
				$durations['__total'][$bucket] = $count;
			}
			elseif(substr($field, 0, 2) === 'd:')
			{
				// the bucket index is whatever follows the LAST colon — route
				// patterns may carry colons of their own
				$cut = strrpos($field, ':');
				$route = substr($field, 2, $cut - 2);
				$durations[$route] ??= array_fill(0, self::DURATION_BUCKETS, 0);
				$durations[$route][(int)substr($field, $cut + 1)] = $count;
			}
			elseif(substr($field, 0, 2) === 'r:')
			{
				$payload['routes'][substr($field, 2)] = $count;
			}
			elseif(substr($field, 0, 2) === 'a:')
			{
				$payload['authed'][substr($field, 2)] = $count;
			}
		}
		
		// the console requires the __total headline whenever the map is
		// non-empty; a partial eviction that lost the dt:* keys ships NO
		// histograms rather than a fragment the endpoint would refuse whole
		if(isset($durations['__total']))
		{
			$payload['durations'] = $durations;
		}
		
		return $payload;
	}
	
	/**
	 * A hostname reduced to the console's identity shape ([A-Za-z0-9._-],
	 * max 64) — '' when nothing usable remains
	 */
	public static function hostName(
		string $raw,
	): string
	{
		$host = (string)preg_replace('~[^A-Za-z0-9._-]~', '-', $raw);
		
		return substr($host, 0, 64);
	}
	
	/**
	 * One field set per request. apcu_inc() creates absent keys at 1 with
	 * the TTL, so there is no init step and no race.
	 */
	protected function count(
		int $minute,
		int|false $status,
		string $method,
		string $route,
		?bool $authed,
		?float $durationMs = null,
	): void
	{
		$fields = ['requests'];
		
		if(is_int($status) && $status >= 100 && $status <= 599)
		{
			$fields[] = 's:' . $status;
		}
		
		if(in_array($method, self::METHODS, true))
		{
			$fields[] = 'm:' . $method;
		}
		
		$fields[] = 'r:' . $route;
		
		// null = the app cannot answer the question; no dimension at all is
		// better than a wrong split
		if($authed !== null)
		{
			$fields[] = 'a:' . ($authed ? 'yes' : 'no');
		}
		
		// the duration histogram (perf-lite): one increment into the fixed
		// bucket vocabulary — the __total headline and the route's own
		// vector, always together, so the console's counts and percentiles
		// can never describe different route sets
		if($durationMs !== null)
		{
			$bucket = self::bucketFor($durationMs);
			$fields[] = 'dt:' . $bucket;
			$fields[] = 'd:' . $route . ':' . $bucket;
		}
		
		$ok = false;
		foreach($fields as $field)
		{
			apcu_inc($this->key($minute . ':' . $field), 1, $ok, self::COUNTER_TTL);
		}
	}
	
	/**
	 * Ships complete minutes once per boundary: the watermark keeps the
	 * common case (same minute as the last flush) to one apcu_fetch, the
	 * add()-lock keeps racing requests from shipping the same minute twice
	 * with two different seqs — which the server-side dedup could not
	 * catch, and additive counters never recover from.
	 */
	protected function maybeFlush(
		int $minute,
	): void
	{
		$flushed = apcu_fetch($this->key(self::FLUSHED_KEY));
		if(is_int($flushed) && $flushed >= $minute - 1)
		{
			return;
		}
		
		// fresh APCu epoch: nothing older than us exists — start the
		// watermark, ship nothing
		if($flushed === false && apcu_add($this->key(self::FLUSHED_KEY), $minute - 1))
		{
			return;
		}
		
		if(apcu_add($this->key(self::LOCK_KEY), 1, 30) === false)
		{
			return; // someone else is flushing
		}
		
		try
		{
			$this->flush($minute);
		}
		finally
		{
			apcu_delete($this->key(self::LOCK_KEY));
		}
	}
	
	/**
	 * Collect every complete minute's counters, ship the fresh ones (up to
	 * FLUSH_MAX per pass), drop the ones the console would refuse as stale,
	 * and advance the watermark when the backlog is drained.
	 */
	protected function flush(
		int $minute,
	): void
	{
		$byMinute = [];
		
		// the install namespace is part of the pattern: another install sharing
		// this pool is not even visible to the iterator
		$pattern = '~^' . preg_quote($this->keyPrefix, '~') . '(\d+):(.+)$~';
		foreach(new APCUIterator($pattern) as $entry)
		{
			if(preg_match($pattern, (string)$entry['key'], $match) !== 1)
			{
				continue;
			}
			
			$entryMinute = (int)$match[1];
			if($entryMinute >= $minute)
			{
				continue; // still accumulating
			}
			
			$byMinute[$entryMinute][$match[2]] = (int)$entry['value'];
		}
		
		ksort($byMinute);
		
		$shipped = 0;
		$drained = true;
		
		foreach($byMinute as $entryMinute => $fields)
		{
			if($entryMinute >= $minute - self::SKEW_MINUTES && $shipped >= self::FLUSH_MAX)
			{
				$drained = false; // the next boundary crossing continues
				
				break;
			}
			
			foreach($fields as $field => $count)
			{
				apcu_delete($this->key($entryMinute . ':' . $field));
			}
			
			// too stale for the console's skew window — deleted, not shipped
			if($entryMinute < $minute - self::SKEW_MINUTES)
			{
				continue;
			}
			
			$this->send($this->payload($entryMinute, $fields));
			$shipped++;
		}
		
		if($drained)
		{
			apcu_store($this->key(self::FLUSHED_KEY), $minute - 1);
		}
	}
	
	/**
	 * The finished fragment: the assembled counters plus the APCu-held
	 * identity — host, the pool marker, and the monotonic seq the console
	 * dedups retries by
	 */
	protected function payload(
		int $minute,
		array $fields,
	): array
	{
		return [
			'v' => 1,
			'type' => 'rollup',
			'host' => self::hostName((string)gethostname()),
			'instance' => $this->instance(),
			'seq' => (int)apcu_inc($this->key(self::SEQ_KEY)),
		] + self::assemble($minute, $fields);
	}
	
	/**
	 * The pool identity, minted once per APCu epoch and per install: the
	 * first worker to ask stores its pid plus the epoch time, so a
	 * recycled pid after an APCu restart is still a NEW identity — the
	 * console's dedup set for the old one must never answer for the new
	 * one's fresh seq counter.
	 *
	 * The key prefix is folded in as well, because the VALUE has to differ
	 * between installs too: one pool means one worker serving both, so pid
	 * and second can be identical — two installs would then ship as the
	 * same (instance, seq) and the console would dedup one of them away.
	 */
	protected function instance(): string
	{
		$instance = apcu_entry($this->key(self::INSTANCE_KEY),
			fn(): string => 'p' . (int)getmypid()
				. '-' . base_convert((string)time(), 10, 36)
				. '-' . base_convert((string)crc32($this->keyPrefix), 10, 36));
		
		return (string)$instance;
	}
	
	/**
	 * The coarse split that makes an attack wave against a logged-in area
	 * read differently from one against public pages — answered by the Auth
	 * service's RESOLVED USER, never by session presence: session behaviour
	 * varies per app (never started, started only after login, auto-started
	 * for everyone), so it can mean anything.
	 *
	 * And only by an Auth service THE REQUEST ITSELF already resolved. This
	 * runs at shutdown as a pure observer; resolving the service here would
	 * run its constructor, and Auth subclasses restore their user FROM THE
	 * SESSION there — which on a session-after-login app would start a
	 * session for an anonymous request, as a metrics side effect. A request
	 * that never touched auth answers null and ships no a: dimension.
	 */
	protected function isAuthed(
		Application $app,
	): ?bool
	{
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
	
	protected static function hasApcu(): bool
	{
		return function_exists('apcu_enabled') && apcu_enabled();
	}
	
	/**
	 * Fire-and-forget POST, the Sender's transport contract: NOSIGNAL for
	 * poll-based sub-second timeouts, hard bounds, no reading the answer —
	 * the console answers duplicates with a 2xx anyway
	 */
	protected function send(
		array $payload,
	): void
	{
		$handle = curl_init(
			rtrim((string)$this->config?->url, '/') . '/api/v1/ingest/rollup');
		
		curl_setopt_array($handle, [
			CURLOPT_POST => true,
			CURLOPT_POSTFIELDS => (string)json_encode($payload,
				JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR),
			CURLOPT_HTTPHEADER => [
				'Content-Type: application/json',
				'X-Console-Key: ' . (string)$this->config?->key,
			],
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_NOSIGNAL => true,
			CURLOPT_CONNECTTIMEOUT_MS => 300,
			CURLOPT_TIMEOUT_MS => (int)($this->config?->timeout_ms ?? 1000),
		]);
		
		curl_exec($handle);
	}
}
