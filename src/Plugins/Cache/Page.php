<?php
declare(strict_types=1);

namespace Ovos\Plugins\Cache;

use Ovos\Cache\Holes;
use Ovos\Cache\Page as PageAttribute;
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
use function array_key_exists;
use function hash;
use function http_build_query;
use function implode;
use function in_array;
use function is_array;
use function ksort;
use function parse_str;
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
	 * Per-process "Class::action" => ?Page attribute lookup cache
	 */
	protected static array $attributes = [];
	
	protected ?PageAttribute $attribute = null;
	
	protected ?string $key = null;
	
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
		
		$record = $store->get($this->key);
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
			/** @var Html $response */
			$this->getStore()?->set(
				$this->key,
				$this->record($response),
				$this->attribute->ttl,
				$this->attribute->tags,
			);
		}
		
		// the render emitted sentinels - fill them for THIS visitor too,
		// cacheable or not (hit and miss produce identical output)
		if($response instanceof Html
			&& $holes->contains((string)$response) === true)
		{
			$response->set($holes->fill((string)$response));
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
		
		$this->debugHeader($response, 'HIT');
		
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
		
		$body = (string)$response;
		$holes = $this->holes();
		
		if($holes->contains($body) === true)
		{
			return [...$record, ...$holes->split($body)];
		}
		
		return [...$record, 'body' => $body];
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
