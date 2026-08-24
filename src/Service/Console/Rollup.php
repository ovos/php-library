<?php
declare(strict_types=1);

namespace Ovos\Service\Console;

use APCUIterator;
use Ovos\Application;
use Ovos\ArrayObject;
use Ovos\Controller;
use Ovos\Request;
use Ovos\Service\Session;
use Throwable;

use function apcu_add;
use function apcu_delete;
use function apcu_enabled;
use function apcu_entry;
use function apcu_fetch;
use function apcu_inc;
use function apcu_store;
use function base_convert;
use function curl_exec;
use function curl_init;
use function curl_setopt_array;
use function function_exists;
use function getmypid;
use function gethostname;
use function http_response_code;
use function in_array;
use function intdiv;
use function is_int;
use function json_encode;
use function ksort;
use function preg_match;
use function preg_quote;
use function preg_replace;
use function rtrim;
use function str_contains;
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
	public const string PREFIX = 'ovos:console:rollups:';
	
	protected const string LOCK_KEY = self::PREFIX . 'lock';
	
	protected const string SEQ_KEY = self::PREFIX . 'seq';
	
	protected const string INSTANCE_KEY = self::PREFIX . 'instance';
	
	protected const string FLUSHED_KEY = self::PREFIX . 'flushed';
	
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
	
	public function __construct(
		protected ?ArrayObject $config,
	)
	{
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
		
		$this->count(
			$minute,
			http_response_code(),
			(string)($_SERVER['REQUEST_METHOD'] ?? ''),
			self::routeOf($app->getRequest()),
			$this->isAuthed($app),
		);
		
		$this->maybeFlush($minute);
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
			elseif(substr($field, 0, 2) === 'r:')
			{
				$payload['routes'][substr($field, 2)] = $count;
			}
			elseif(substr($field, 0, 2) === 'a:')
			{
				$payload['authed'][substr($field, 2)] = $count;
			}
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
		bool $authed,
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
		$fields[] = 'a:' . ($authed ? 'yes' : 'no');
		
		$ok = false;
		foreach($fields as $field)
		{
			apcu_inc(self::PREFIX . $minute . ':' . $field, 1, $ok, self::COUNTER_TTL);
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
		$flushed = apcu_fetch(self::FLUSHED_KEY);
		if(is_int($flushed) && $flushed >= $minute - 1)
		{
			return;
		}
		
		// fresh APCu epoch: nothing older than us exists — start the
		// watermark, ship nothing
		if($flushed === false && apcu_add(self::FLUSHED_KEY, $minute - 1))
		{
			return;
		}
		
		if(apcu_add(self::LOCK_KEY, 1, 30) === false)
		{
			return; // someone else is flushing
		}
		
		try
		{
			$this->flush($minute);
		}
		finally
		{
			apcu_delete(self::LOCK_KEY);
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
		
		$pattern = '~^' . preg_quote(self::PREFIX, '~') . '(\d+):(.+)$~';
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
				apcu_delete(self::PREFIX . $entryMinute . ':' . $field);
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
			apcu_store(self::FLUSHED_KEY, $minute - 1);
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
			'seq' => (int)apcu_inc(self::SEQ_KEY),
		] + self::assemble($minute, $fields);
	}
	
	/**
	 * The pool identity, minted once per APCu epoch: the first worker to
	 * ask stores its pid plus the epoch time, so a recycled pid after an
	 * APCu restart is still a NEW identity — the console's dedup set for
	 * the old one must never answer for the new one's fresh seq counter.
	 */
	protected function instance(): string
	{
		$instance = apcu_entry(self::INSTANCE_KEY,
			static fn(): string => 'p' . (int)getmypid()
				. '-' . base_convert((string)time(), 10, 36));
		
		return (string)$instance;
	}
	
	/**
	 * Session-backed requests count as authed — the coarse two-way split
	 * that makes an attack wave against a logged-in area read differently
	 * from one against public pages
	 */
	protected function isAuthed(
		Application $app,
	): bool
	{
		try
		{
			$session = $app->getServices()->session;
			
			return $session instanceof Session && $session->getId() !== null;
		}
		catch(Throwable)
		{
			return false;
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
