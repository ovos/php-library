<?php
declare(strict_types=1);

namespace Ovos\Plugins\Cache;

use Ovos\Cache\Holes;
use Ovos\Cache\Page as PageAttribute;
use Ovos\Cache\Store\KeyValue\Redis as RedisStore;
use Ovos\Cache\Store\KeyValue\Tags;
use Ovos\Console;
use Ovos\Controller\Plugin;
use Ovos\Request;
use Ovos\Response;
use Ovos\Response\Html;
use Ovos\Service\Cache;
use Override;
use ReflectionException;
use ReflectionMethod;
use Throwable;

use function Ovos\config;
use function apcu_fetch;
use function apcu_store;
use function array_key_exists;
use function function_exists;
use function hash;
use function http_build_query;
use function implode;
use function in_array;
use function is_array;
use function ksort;
use function parse_str;
use function str_contains;
use function strcasecmp;
use function strpos;
use function substr;
use function time;

/**
 * Page cache
 *
 * Serves and captures the full HTML response of any action marked with
 * #[Cache\Page]. Register as a default HTTP plugin AFTER the layout
 * plugin - postDispatch runs in registration order, and the capture must
 * see the FINAL page (layout applied), not the bare action output:
 *
 *   system:
 *     plugins:
 *       default:
 *         http:
 *           - Locales
 *           - Layout\Page
 *           - \Ovos\Plugins\Cache\Page
 *
 * On a hit it replays the stored body and stops dispatch (no plugin
 * postDispatch runs - the stored page is already final); on a miss it lets
 * the action run and stores the rendered body (with its tags) afterwards.
 * Only GET/HEAD are cached, only 200 responses, and never one that sets a
 * cookie.
 *
 * HOLES - late-bound per-request bits inside the shared shell: a template
 * emits `$this->hole('csrf', fn() => …)` and the body is stored PRE-SPLIT
 * at the sentinels (segments + hole names); serving interleaves stored
 * segments with freshly resolved values, no parsing per hit. On a hit the
 * templates never ran, so every hole must be resolvable from an
 * always-running registration (Holes::provide() in a plugin) - a hit that
 * cannot fill each hole falls back to a miss and notes it in the dev
 * console.
 *
 * STALE-WHILE-REVALIDATE - `#[Cache\Page(ttl: 300, stale: 3600)]` keeps
 * serving the page for up to an hour past its freshness instantly, while
 * ONE elected request (SET NX lock) takes the miss path and rebuilds it -
 * no visitor ever waits on a render, and there is no rebuild stampede.
 *
 * APCU FRONT TIER - `apcu: 5` additionally keeps the record in per-worker
 * memory for a few seconds: the hottest shells serve with ZERO network
 * round trips, and a tag invalidation lags this tier by at most that ttl.
 * (RESP3 client-side-caching invalidation push was spiked and parked:
 * phpredis 6.3 exposes no workable push wiring for FPM - the short ttl IS
 * the coherence bound.)
 *
 * CONDITIONAL SERVING - every 200 carries a strong ETag of its final body
 * (holes filled); a matching If-None-Match collapses the transfer to a
 * bodyless 304.
 *
 * App-specific vary axes (e.g. an authenticated-user split) are added by
 * subclassing and overriding varyValue().
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Page extends Plugin
{
	public const string SYMBOL = 'pageCache';
	
	public const string KEY_PREFIX = 'page:';
	
	/**
	 * Request methods whose response is safe to replay
	 */
	protected const array CACHEABLE_METHODS = [
		Request::METHOD_GET,
		Request::METHOD_HEAD,
	];
	
	/**
	 * Upper bound on one revalidation - a dead winner frees the next
	 * election after at most this many seconds
	 */
	protected const int REVALIDATE_LOCK_TTL = 30;
	
	/**
	 * Per-process "Class::action" => ?Page attribute lookup cache
	 */
	protected static array $attributes = [];
	
	protected ?PageAttribute $attribute = null;
	
	protected ?string $key = null;
	
	/**
	 * This request won the revalidation election for a stale record
	 */
	protected bool $revalidating = false;
	
	#[Override]
	public function preDispatch(): void
	{
		$this->attribute = $this->resolveAttribute();
		if($this->attribute === null)
		{
			return; // action is not annotated - the plugin stays inert
		}
		
		if(self::isCacheableRequest($this->request->getMethod()) === false)
		{
			$this->attribute = null;
			
			return;
		}
		
		$store = $this->getStore();
		if($store === null)
		{
			$this->attribute = null; // cache disabled or non-tagging store
			
			return;
		}
		
		$this->key = $this->buildKey();
		
		// per-worker front tier first: the hottest shells serve without a
		// network round trip; a redis hit backfills it
		$record = $this->fromApcu();
		if($record === null
			&& is_array($record = $store->get($this->key)) === true)
		{
			$this->toApcu($record);
		}
		
		if(is_array($record) === true
			&& $this->serve($record) === true)
		{
			return;
		}
		
		// miss (or a record we cannot serve) - let the action run with
		// hole capturing on; postDispatch splits and stores the result
		$this->holes()->capturing(true);
	}
	
	#[Override]
	public function postDispatch(): void
	{
		// only a miss reaches here: a hit called setDispatched(true) in
		// preDispatch, and postDispatchPlugins() breaks on isDispatched()
		if($this->attribute === null || $this->key === null)
		{
			return;
		}
		
		$holes = $this->holes();
		$holes->capturing(false);
		
		$response = $this->app->getResponse();
		$this->debugHeader($response, 'MISS');
		
		if(self::isCacheableResponse($response) === true)
		{
			// the key survives the stale window on top of the fresh ttl
			$ttl = $this->attribute->ttl > 0
				? $this->attribute->ttl + $this->attribute->stale
				: $this->attribute->ttl;
				
			/** @var Html $response */
			$record = $this->record($response);
			
			$this->getStore()?->set(
				$this->key,
				$record,
				$ttl,
				$this->attribute->tags,
			);
			$this->toApcu($record);
		}
		
		// a revalidation winner releases its lock - the next stale window
		// can elect a new winner immediately
		$this->releaseRevalidateLock();
		
		// the render emitted sentinels - fill them for THIS visitor too,
		// cacheable or not (hit and miss produce identical output)
		if($response instanceof Html
			&& $holes->contains((string)$response) === true)
		{
			$response->set($holes->fill((string)$response));
		}
		
		// conditional serving on the final (post-fill) body
		if($response instanceof Html)
		{
			$this->conditional($response);
		}
	}
	
	/**
	 * Replay a stored record as the response and skip the action; false
	 * when the record cannot be served (a hole with no provider) - the
	 * caller then falls through to the miss path
	 */
	protected function serve(
		array $record,
	): bool
	{
		$state = 'HIT';
		
		// stale-while-revalidate: past freshUntil the record still serves
		// instantly - except for ONE winner, who takes the miss path and
		// rebuilds while everyone else keeps getting the stale shell
		if(self::isFresh($record) === false)
		{
			if($this->tryRevalidateLock() === true)
			{
				return false; // this request revalidates
			}
			
			$state = 'STALE';
		}
		
		$holes = (array)($record['holes'] ?? []);
		
		if($holes === [])
		{
			$body = (string)($record['body'] ?? '');
		}
		else
		{
			// a hit must not ship a page with unfillable holes - the
			// templates never ran, so only always-registered providers count
			if($this->holes()->canResolveAll($holes) === false)
			{
				$this->note('page cache: hit not served - unprovided hole(s) ['
					. implode(', ', $holes) . '] on ' . $this->key
					. ' - register them via Holes::provide() in a plugin');
					
				return false;
			}
			
			$body = $this->holes()->assemble(
				(array)($record['segments'] ?? []),
				$holes,
			);
		}
		
		$response = new Html($body);
		$response->setHttpCode((int)($record['code'] ?? 200));
		
		foreach((array)($record['headers'] ?? []) as $name => $value)
		{
			$response->setHeader((string)$name, $value, true);
		}
		
		$this->debugHeader($response, $state);
		$this->conditional($response);
		
		$this->app->setResponse($response);
		$this->getController()->setDispatched(true);
		
		return true;
	}
	
	/**
	 * The storable record: a plain body, or - when the render emitted hole
	 * sentinels - the body pre-split into segments + hole names, so a hit
	 * assembles without ever parsing
	 *
	 * @return array{code: int, headers: array, savedAt: int, ...}
	 */
	protected function record(
		Html $response,
	): array
	{
		$headers = [];
		foreach($response->getHeaders() as $name => $header)
		{
			$headers[$name] = $header['value'] ?? null;
		}
		
		$record = [
			'code' => $response->getHttpCode(),
			'headers' => $headers,
			'savedAt' => time(),
		];
		
		// stale-while-revalidate: freshness is tracked INSIDE the record
		// while the key survives ttl + stale
		if($this->attribute->stale > 0 && $this->attribute->ttl > 0)
		{
			$record['freshUntil'] = time() + $this->attribute->ttl;
		}
		
		$body = (string)$response;
		$holes = $this->holes();
		
		if($holes->contains($body) === true)
		{
			return [...$record, ...$holes->split($body)];
		}
		
		return [...$record, 'body' => $body];
	}
	
	/**
	 * The per-worker front tier: a record recently seen by THIS worker,
	 * served without touching redis. Bounded by the attribute's short
	 * apcu ttl - which is also how long a tag invalidation may lag here.
	 */
	protected function fromApcu(): ?array
	{
		if($this->attribute->apcu < 1
			|| function_exists('apcu_fetch') === false)
		{
			return null;
		}
		
		$record = apcu_fetch(self::KEY_PREFIX . 'apcu:' . $this->key);
		
		return is_array($record) === true ? $record : null;
	}
	
	protected function toApcu(
		array $record,
	): void
	{
		if($this->attribute->apcu > 0
			&& function_exists('apcu_store') === true)
		{
			apcu_store(
				self::KEY_PREFIX . 'apcu:' . $this->key,
				$record,
				$this->attribute->apcu,
			);
		}
	}
	
	/**
	 * The strong ETag of a response body
	 */
	public static function etagFor(
		string $body,
	): string
	{
		return '"' . hash('xxh128', $body) . '"';
	}
	
	/**
	 * Conditional serving: every 200 carries an ETag of its FINAL body
	 * (holes filled - each visitor validates their own bytes), and a
	 * matching If-None-Match collapses the transfer to a bodyless 304
	 */
	protected function conditional(
		Html $response,
	): void
	{
		if($response->getHttpCode() !== 200)
		{
			return;
		}
		
		$etag = self::etagFor((string)$response);
		$response->setHeader('ETag', $etag, true);
		
		$ifNoneMatch = (string)$this->request->getServer('HTTP_IF_NONE_MATCH');
		if($ifNoneMatch !== ''
			&& str_contains($ifNoneMatch, $etag) === true)
		{
			$response->setHttpCode(304);
			$response->set('');
		}
	}
	
	/**
	 * Fresh unless the record carries a freshUntil in the past (a record
	 * without one has no stale window - hard TTL is its only clock)
	 */
	public static function isFresh(
		array $record,
		?int $now = null,
	): bool
	{
		$freshUntil = $record['freshUntil'] ?? null;
		
		if($freshUntil === null)
		{
			return true;
		}
		
		return ($now ?? time()) <= (int)$freshUntil;
	}
	
	/**
	 * One winner per stale window: SET NX with a bounded ttl, so a dead
	 * winner frees the election after REVALIDATE_LOCK_TTL at worst. No
	 * client (non-Redis store) means no election - the caller treats the
	 * stale record as a plain miss.
	 */
	protected function tryRevalidateLock(): bool
	{
		$store = $this->getStore();
		if(($store instanceof RedisStore) === false
			|| ($client = $store->getClient()) === null)
		{
			return true; // no lock possible - rebuild rather than serve stale forever
		}
		
		try
		{
			$this->revalidating = (bool)$client->set(
				$this->revalidateLockKey($store),
				'1',
				['nx', 'ex' => self::REVALIDATE_LOCK_TTL],
			);
		}
		catch(Throwable)
		{
			$this->revalidating = false;
		}
		
		return $this->revalidating;
	}
	
	protected function releaseRevalidateLock(): void
	{
		if($this->revalidating === false)
		{
			return;
		}
		
		$this->revalidating = false;
		
		$store = $this->getStore();
		if($store instanceof RedisStore
			&& ($client = $store->getClient()) !== null)
		{
			try
			{
				$client->del($this->revalidateLockKey($store));
			}
			catch(Throwable)
			{
				// the lock ttl reclaims it
			}
		}
	}
	
	protected function revalidateLockKey(
		RedisStore $store,
	): string
	{
		return $store->getPrefixer()
			->prefix($this->key . ':revalidate');
	}
	
	protected function holes(): Holes
	{
		return $this->container->getClass(Holes::class);
	}
	
	/**
	 * A dev-console note (visible in the profiler panel)
	 */
	protected function note(
		string $message,
	): void
	{
		try
		{
			$this->container->getClass(Console::class)
				->setMessage($message);
		}
		catch(Throwable)
		{
			// no console in this context - the behaviour still stands
		}
	}
	
	protected function buildKey(): string
	{
		return self::cacheKey(
			$this->app->getInterface(),
			(string)$this->request->getServer('REQUEST_URI'),
			$this->varyValues($this->attribute->vary),
		);
	}
	
	/**
	 * @param string[] $vary
	 * @return array<string, string>
	 */
	protected function varyValues(
		array $vary,
	): array
	{
		$values = [];
		foreach($vary as $axis)
		{
			$values[$axis] = $this->varyValue($axis);
		}
		
		return $values;
	}
	
	/**
	 * The value the key varies on for a given axis. 'locale' is built in
	 * (the framework already carries it in the URL path, but an explicit
	 * axis is harmless); app-specific axes (auth, tenant, …) override this.
	 */
	protected function varyValue(
		string $axis,
	): string
	{
		return match($axis)
		{
			'locale' => $this->request->getLocale()->getUrlName(),
			default => '',
		};
	}
	
	protected function resolveAttribute(): ?PageAttribute
	{
		$controller = $this->getController();
		$action = $controller->getDispatchedAction();
		$cacheKey = $controller::class . '::' . $action;
		
		if(array_key_exists($cacheKey, self::$attributes) === true)
		{
			return self::$attributes[$cacheKey];
		}
		
		$attribute = null;
		
		try
		{
			$attributes = (new ReflectionMethod($controller, $action))
				->getAttributes(PageAttribute::class);
			if($attributes !== [])
			{
				$attribute = $attributes[0]->newInstance();
			}
		}
		catch(ReflectionException)
		{
			// no such method - leave the plugin inert
		}
		
		return self::$attributes[$cacheKey] = $attribute;
	}
	
	protected function getStore(): ?Tags
	{
		$store = $this->container
			->get(Cache::SYMBOL)
			->getPersistent()
			->getStore();
			
		return $store instanceof Tags ? $store : null;
	}
	
	protected function debugHeader(
		?Response $response,
		string $state,
	): void
	{
		if($response !== null && $this->isDebug() === true)
		{
			$response->setHeader('X-Ovos-Cache', $state, true);
		}
	}
	
	protected function isDebug(): bool
	{
		return (bool)(config()->system->debug ?? false);
	}
	
	public static function isCacheableRequest(
		string $method,
	): bool
	{
		return in_array($method, self::CACHEABLE_METHODS, true);
	}
	
	public static function isCacheableResponse(
		?Response $response,
	): bool
	{
		if(($response instanceof Html) === false
			|| $response->getHttpCode() !== 200)
		{
			return false;
		}
		
		// never cache a response that sets a cookie (session, flash,
		// personalisation) - it would leak one visitor's state to the next
		foreach($response->getHeaders() as $name => $header)
		{
			if(strcasecmp((string)$name, 'Set-Cookie') === 0)
			{
				return false;
			}
		}
		
		return true;
	}
	
	/**
	 * Deterministic cache key: interface + canonicalised URI + vary pairs
	 *
	 * @param array<string, string> $vary
	 */
	public static function cacheKey(
		string $interface,
		string $uri,
		array $vary = [],
	): string
	{
		$canonical = $interface . ' ' . self::canonicalUri($uri);
		foreach($vary as $axis => $value)
		{
			$canonical.= '|' . $axis . '=' . $value;
		}
		
		return self::KEY_PREFIX . hash('xxh128', $canonical);
	}
	
	/**
	 * Drop the fragment and sort the query so ?a=1&b=2 and ?b=2&a=1 share a
	 * key instead of fragmenting the cache
	 */
	public static function canonicalUri(
		string $uri,
	): string
	{
		$fragment = strpos($uri, '#');
		if($fragment !== false)
		{
			$uri = substr($uri, 0, $fragment);
		}
		
		$mark = strpos($uri, '?');
		if($mark === false)
		{
			return $uri;
		}
		
		$path = substr($uri, 0, $mark);
		parse_str(substr($uri, $mark + 1), $params);
		ksort($params);
		
		$query = http_build_query($params);
		
		return $query === '' ? $path : $path . '?' . $query;
	}
}
