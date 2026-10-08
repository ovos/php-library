<?php
declare(strict_types=1);

namespace Ovos\Plugins\Cache;

use Ovos\Cache\Holes;
use Ovos\Cache\Page as PageAttribute;
use Ovos\Cache\Stale;
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

use function apcu_fetch;
use function apcu_store;
use function array_flip;
use function array_intersect_key;
use function array_key_exists;
use function function_exists;
use function hash;
use function http_build_query;
use function implode;
use function in_array;
use function is_array;
use function ksort;
use function Ovos\config;
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
 * serving the page for up to an hour past its freshness, at once, while ONE
 * request - elected without waiting (MemoLock::tryLock()) - renders the
 * fresh one. That visitor gets the stale page too: its response is finished
 * early (Application::finishResponse()) and the render goes on with nobody
 * waiting; only where a client cannot be released early (mod_php) does that
 * one visitor wait for the render. The fresh page's write is guarded: an
 * invalidation (an edit) landing during the render refuses it, so the page
 * from before the edit never comes back. A miss renders once while the
 * other requests for the page wait for it (MemoLock) - no render stampede.
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
	 * Upper bound on one render, for a miss and a revalidation alike: a
	 * renderer that dies frees the page's lock after at most this long
	 * Unit: milliseconds
	 */
	protected const int LOCK_TTL_MS = 30000;
	
	/**
	 * Per-process "Class::action" => ?Page attribute lookup cache
	 */
	protected static array $attributes = [];
	
	protected ?PageAttribute $attribute = null;
	
	protected ?string $key = null;
	
	/**
	 * This request holds the page's lock (a miss, or the elected revalidation):
	 * the stored page releases it, an uncacheable one by hand
	 */
	protected bool $locked = false;
	
	/**
	 * This request sent its visitor the stale page and renders after it
	 * (Application::finishResponse()) - nothing else goes to the client
	 */
	protected bool $finishedEarly = false;
	
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
		// network round trip; a redis hit backfills it. peek() hands back a
		// page past its ttl too - get() would take it for a miss
		$record = $this->servable($this->fromApcu());
		if($record === null
			&& ($record = $this->servable($store->peek($this->key))) !== null)
		{
			$this->toApcu($record);
		}
		
		if($record === null && $store->isQueueEnabled() === true)
		{
			// a miss: one request renders while the others wait for its page
			// (MemoLock) - a page rendered meanwhile comes back, nothing means
			// this request holds the lock and renders
			$record = $store->lockAndQueue($this->key, queueLockTtlMs: static::LOCK_TTL_MS);
			$this->locked = $record === null;
			$this->releaseAtTheEnd($store);
		}
		
		if($record instanceof Stale && $record->isFresh() === false)
		{
			$this->revalidate($store, $record->value);
			
			return;
		}
		
		if($record instanceof Stale)
		{
			$record = $record->value;
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
	
	/**
	 * A page past its ttl, inside its stale time: served at once - and ONE
	 * request, elected without waiting, renders the fresh one. Its visitor
	 * gets the stale page too, the response finished early, and the render
	 * goes on with nobody waiting; where the client cannot be released early
	 * (mod_php) that visitor waits for the render, as on a miss. The elected
	 * request reads the store once more first (peek()): another worker may
	 * have rendered the page already (this worker's APCu copy is older), an
	 * invalidation may have removed it - then it is not served stale but
	 * rendered - and that read is what guards the fresh page's write: an
	 * invalidation landing while it renders refuses it
	 */
	protected function revalidate(
		Tags $store,
		mixed $record,
	): void
	{
		$elected = $store->getMemoLock()
			->tryLock($store->itemId($this->key), static::LOCK_TTL_MS);
		
		if($elected === false)
		{
			if(is_array($record) === true
				&& $this->serve($record, 'STALE') === true)
			{
				return;
			}
			
			// a page that cannot be served (an unprovided hole) renders
			$this->holes()->capturing(true);
			
			return;
		}
		
		$this->locked = true;
		$this->releaseAtTheEnd($store);
		
		$current = $store->peek($this->key);
		if(($current instanceof Stale && $current->isFresh()) || is_array($current) === true)
		{
			// rendered meanwhile: nothing to do but serve it
			$store->releaseActiveLock($this->key);
			$this->locked = false;
			$this->toApcu($current);
			
			if($this->serve($current instanceof Stale ? $current->value : $current) === false)
			{
				$this->holes()->capturing(true);
			}
			
			return;
		}
		
		// the render starts now: that read is the miss the page's write
		// follows - an invalidation landing while it renders reaches it
		$store->startComputing($this->key);
		
		// still past its ttl: its visitor gets it now, the render goes on;
		// gone (invalidated): no stale page - the visitor waits for the render
		$response = $current instanceof Stale && is_array($current->value) === true
			? $this->replay($current->value, 'STALE')
			: null;
		if($response !== null)
		{
			$this->finishedEarly = $this->app->finishResponse($response);
		}
		
		// the render: the action runs, postDispatch stores the fresh page
		$this->holes()->capturing(true);
	}
	
	/**
	 * An action that throws never reaches postDispatch(): the lock this
	 * request holds is let go once the request has ended (its error page
	 * sent), not at its TTL. A stored page or postDispatch() released it
	 * before - then this does nothing
	 */
	protected function releaseAtTheEnd(
		Tags $store,
	): void
	{
		if($this->locked === false)
		{
			return;
		}
		
		$this->app->afterResponse(function() use ($store): void
		{
			if($this->locked === true)
			{
				$store->releaseActiveLock($this->key);
				$this->locked = false;
			}
		});
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
			/** @var Html $response */
			$this->store($response);
		}
		else if($this->locked === true)
		{
			// nothing stored releases the lock: released by hand, the next
			// request renders at once instead of after LOCK_TTL_MS
			$this->getStore()?->releaseActiveLock($this->key);
		}
		$this->locked = false;
		
		// the visitor left with the stale page; the fresh one is stored
		if($this->finishedEarly === true)
		{
			return;
		}
		
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
		string $state = 'HIT',
	): bool
	{
		$response = $this->replay($record, $state);
		if($response === null)
		{
			return false;
		}
		
		$this->app->setResponse($response);
		$this->getController()->setDispatched(true);
		
		return true;
	}
	
	/**
	 * A stored record as a response - its holes filled, its ETag set, a 304
	 * where the visitor has it already; null when it cannot be served (a
	 * hole with no provider)
	 */
	protected function replay(
		array $record,
		string $state,
	): ?Html
	{
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
				
				return null;
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
		
		return $response;
	}
	
	/**
	 * Stores the rendered page: with a stale time as a Stale - fresh for
	 * the ttl, kept the stale time past it - and through the store's guard,
	 * so a page rendered across an invalidation is refused; set() releases
	 * the page's lock either way
	 */
	protected function store(
		Html $response,
	): void
	{
		// with a stale time: a record carrying it, stored that much longer
		[$record, $ttl] = Stale::wrap($this->record($response), $this->attribute->ttl, $this->attribute->stale);
		
		// a page the guard refused (an invalidation while it rendered) is
		// not this worker's copy either
		if($this->getStore()?->set(
			$this->key,
			$record,
			$ttl,
			$this->attribute->tags,
		) === true)
		{
			$this->toApcu($record);
		}
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
		
		$body = (string)$response;
		$holes = $this->holes();
		
		if($holes->contains($body) === true)
		{
			return [...$record, ...$holes->split($body)];
		}
		
		return [...$record, 'body' => $body];
	}
	
	/**
	 * A stored page as it may be served: a page past its stale time is a
	 * miss, whatever is left of its item - the record's times decide, not the
	 * store's TTL (nor the worker copy's)
	 */
	protected function servable(
		mixed $record,
	): array|Stale|null
	{
		if($record instanceof Stale)
		{
			return $record->isServable()
				? $record
				: null;
		}
		
		return is_array($record) === true
			? $record
			: null;
	}
	
	/**
	 * The per-worker front tier: a record recently seen by THIS worker,
	 * served without touching redis. Bounded by the attribute's short
	 * apcu ttl - which is also how long a tag invalidation may lag here.
	 */
	protected function fromApcu(): array|Stale|null
	{
		if($this->attribute->apcu < 1
			|| function_exists('apcu_fetch') === false)
		{
			return null;
		}
		
		$record = apcu_fetch(self::KEY_PREFIX . 'apcu:' . $this->key);
		
		return is_array($record) === true || $record instanceof Stale
			? $record
			: null;
	}
	
	protected function toApcu(
		array|Stale $record,
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
			$this->attribute->query,
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
		?array $query = null,
	): string
	{
		$canonical = $interface . ' ' . self::canonicalUri($uri, $query);
		foreach($vary as $axis => $value)
		{
			$canonical.= '|' . $axis . '=' . $value;
		}
		
		return self::KEY_PREFIX . hash('xxh128', $canonical);
	}
	
	/**
	 * Drop the fragment and sort the query so ?a=1&b=2 and ?b=2&a=1 share a
	 * key instead of fragmenting the cache; with $query (the action's
	 * allowlist, see Cache\Page) only the parameters it names are kept
	 */
	public static function canonicalUri(
		string $uri,
		?array $query = null,
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
		if($query !== null)
		{
			$params = array_intersect_key($params, array_flip($query));
		}
		ksort($params);
		
		$query = http_build_query($params);
		
		return $query === '' ? $path : $path . '?' . $query;
	}
}
