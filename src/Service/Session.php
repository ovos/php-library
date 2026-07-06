<?php
declare(strict_types=1);

namespace Ovos\Service;

use Ovos\Exception\MissingException\MissingConfigException;
use Ovos\Service;
use Ovos\ArrayObject;
use Ovos\Container\Inject;
use Ovos\Exception;
use Ovos\Connection\Redis as Connection;
use Ovos\Session\Handler\RedisJson;
use Ovos\Session\Index;
use Ovos\Session\Store;
use Ovos\Session\Store\Json;
use Ovos\Session\Store\Native;
use Closure;

use function bin2hex;
use function explode;
use function headers_sent;
use function ini_get;
use function ini_set;
use function is_array;
use function is_string;
use function preg_match;
use function random_bytes;
use function register_shutdown_function;
use function session_cache_limiter;
use function session_get_cookie_params;
use function session_name;
use function session_regenerate_id;
use function session_set_cookie_params;
use function session_start;
use function setcookie;
use function time;

/**
 * Session
 *
 * Two storage handlers, selected by the "session.handler" config:
 *
 * - "php" (default): the native PHP session machinery with whatever
 *   "session.ini" configures (e.g. the phpredis save handler). The whole
 *   session is read at start and written back at the end, guarded by a
 *   session-wide lock.
 * - "json": the lazy RedisJSON handler - values are read and written
 *   directly in Redis at the requested nest level, nothing is loaded up
 *   front, no session-wide lock exists and writes are write-through.
 *   Individual values can be locked for modification (getLocked()).
 *
 * The path API (get/set/has/remove/...) works with both handlers and is
 * the recommended access style; the magic accessors keep their historic
 * by-reference behavior under "php", while under "json" they peek at the
 * stored type - scalars materialize as themselves (legacy reads keep
 * working), containers and missing values are lazy Node objects.
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Session extends Service
{
	public const string SYMBOL = 'session';
	
	// Handlers
	public const string HANDLER_PHP = 'php';
	public const string HANDLER_JSON = 'json';
	
	/**
	 * The session id format minted (and accepted) by the json handler
	 */
	public const string SESSION_ID_PATTERN = '/^[a-f0-9]{32}$/';
	
	protected ArrayObject $config;
	protected ArrayObject $cookiesConfig;
	protected ArrayObject $sessionConfig;
	
	protected string $handler = self::HANDLER_PHP;
	
	protected bool $started = false;
	protected bool $initialized = false;
	
	/**
	 * The storage behind every access, picked when the session starts:
	 * Native ($_SESSION, or a plain array on the CLI) or Json
	 */
	protected ?Store $store = null;
	
	protected ?RedisJson $jsonHandler = null;
	
	/**
	 * An unbound handler for the session-less operations (countActive,
	 * gc, index) - kept apart from $jsonHandler so it never shadows a
	 * started session
	 */
	protected ?RedisJson $transientJsonHandler = null;
	
	public function __construct(
		#[Inject('config')] ArrayObject $config,
	)
	{
		$this->config = $config;
		if($this->config->cookies === null)
		{
			throw new Exception(
				'"cookies" config section is missing.');
		}
		$this->cookiesConfig = $this->config->cookies;
		
		if($this->config->session === null)
		{
			throw new Exception(
				'"session" config section is missing.');
		}
		$this->sessionConfig = $this->config->session;
		
		if($this->sessionConfig->handler !== null)
		{
			$this->handler = (string)$this->sessionConfig->handler;
		}
		
		// the ini block configures the native machinery only
		if($this->handler === self::HANDLER_PHP
			&& $this->sessionConfig->ini)
		{
			foreach($this->sessionConfig->ini as $ini => $value)
			{
				ini_set('session.' . $ini, (string)$value);
			}
		}
	}
	
	public function getHandler(): string
	{
		return $this->handler;
	}
	
	/**
	 * The json storage handler, available once started (http only)
	 */
	public function getJsonHandler(): ?RedisJson
	{
		return $this->jsonHandler;
	}
	
	public function start(): void
	{
		if($this->started === true)
		{
			return;
		}
		
		if($this->request->isCli())
		{
			// CLI has no session; the accessors work on a local,
			// non-persisted array regardless of the handler
			$this->store = new Native;
			$this->started = true;
			
			return;
		}
		
		if($this->handler === self::HANDLER_JSON)
		{
			$this->startJson();
			
			return;
		}
		
		$this->initialize();
		if(session_start() === false)
		{
			throw new Exception('Session could not start.');
		}
		
		$this->store = new Native($_SESSION);
		$this->started = true;
	}
	
	/**
	 * Ends the request's session work: the native store hands the data
	 * back to the machinery (write-back), the json store releases the
	 * held value locks (writes were write-through), waking any waiters
	 */
	public function close(): void
	{
		$this->store?->close();
	}
	
	/**
	 * The active store, starting the session on first access
	 */
	protected function store(): Store
	{
		$this->start();
		
		return $this->store;
	}
	
	/**
	 * @see https://www.php.net/session_regenerate_id
	 */
	public function regenerateId(
		bool $deleteOldSession = true,
	): bool
	{
		if($this->handler === self::HANDLER_JSON)
		{
			$this->start();
			if($this->jsonHandler === null)
			{
				return false;
			}
			
			$sessionId = $this->createSessionId();
			$this->jsonHandler->rename($sessionId, $deleteOldSession);
			$this->sendCookie($sessionId);
			
			return true;
		}
		
		return session_regenerate_id($deleteOldSession);
	}
	
	public function flush(): bool
	{
		if($this->config->session->connection === null)
		{
			throw new MissingConfigException(
				'"connection" config section is missing.');
		}
		
		$connection = new Connection($this->config->session->connection);
		$connectionStatus = $connection->connect();
		if($connectionStatus === false)
		{
			return false;
		}
		
		if($client = $connection->getClient())
		{
			return $client->flushDB();
		}
		
		return false;
	}
	
	/**
	 * Path API, works with both handlers: reads the value at a nested
	 * path ("basket.products" or ['basket', 'products']); under the json
	 * handler only that value is fetched, and a value locked by another
	 * request is waited for
	 */
	public function get(
		string|array $path,
	): mixed
	{
		return $this->store()
			->get($this->path($path));
	}
	
	/**
	 * Reads several paths in one round trip (json handler: a single
	 * multi-path JSON.GET); the values are keyed by the dot-joined path
	 */
	public function getMany(
		array $paths,
	): array
	{
		foreach($paths as $index => $mixedPath)
		{
			$paths[$index] = $this->path($mixedPath);
		}
		
		return $this->store()
			->getMany($paths);
	}
	
	/**
	 * Locks the value, applies the updater to it and writes the result
	 * back, releasing the lock - the safe form of read-modify-write;
	 * under the php handler the session-wide lock already serializes it
	 */
	public function update(
		string|array $path,
		Closure $updater,
	): mixed
	{
		return $this->store()
			->update($this->path($path), $updater);
	}
	
	/**
	 * Reads the value at a nested path and locks it for modification
	 * within this request (json handler); the lock is released by set(),
	 * releaseLock() or close()
	 * The php handler already holds an exclusive session-wide lock, so
	 * this is a plain read there
	 */
	public function getLocked(
		string|array $path,
	): mixed
	{
		return $this->store()
			->getLocked($this->path($path));
	}
	
	/**
	 * Writes the value at a nested path, creating missing parents, and
	 * releases a lock held on it (json handler)
	 */
	public function set(
		string|array $path,
		mixed $value,
	): void
	{
		$this->store()
			->set($this->path($path), $value);
	}
	
	public function has(
		string|array $path,
	): bool
	{
		return $this->store()
			->has($this->path($path));
	}
	
	public function remove(
		string|array $path,
	): void
	{
		$this->store()
			->remove($this->path($path));
	}
	
	/**
	 * Atomically increments a numeric value at a nested path (json
	 * handler; plain read-modify-write under php, which is already
	 * serialized by the session-wide lock)
	 */
	public function increment(
		string|array $path,
		int|float $by = 1,
	): int|float|null
	{
		return $this->store()
			->increment($this->path($path), $by);
	}
	
	/**
	 * Atomically appends a value to a list at a nested path, creating it
	 * when necessary (json handler: lock-free, one round trip); with a
	 * limit the list keeps only its last "limit" entries; returns the
	 * resulting list length
	 */
	public function append(
		string|array $path,
		mixed $value,
		int $limit = 0,
	): ?int
	{
		return $this->store()
			->append($this->path($path), $value, $limit);
	}
	
	/**
	 * Releases a value lock taken by getLocked() without writing;
	 * an unstarted session holds nothing to release
	 */
	public function releaseLock(
		string|array $path,
	): bool
	{
		return $this->store === null
			|| $this->store->releaseLock($this->path($path));
	}
	
	/**
	 * Records a manual user action into the journey timeline,
	 * for example "ticket bought" or "signed up for newsletter"
	 */
	public function addAction(
		string $action,
		array $data = [],
	): void
	{
		$this->store()
			->addAction($action, $data);
	}
	
	/**
	 * Records a request into the journey timeline; with the
	 * "session.journey.requests" config enabled this happens
	 * automatically for every http request
	 */
	public function addRequest(
		string $method,
		string $url,
		array $data = [],
	): void
	{
		$this->store()
			->addRequest($method, $url, $data);
	}
	
	/**
	 * The recorded requests and actions of this session, oldest first -
	 * the timeline / navigation path the user has taken
	 */
	public function getJourney(): array
	{
		return $this->store()
			->getJourney();
	}
	
	/**
	 * How many sessions were active within the given window
	 * (json handler only - the php handler cannot know: null)
	 */
	public function countActive(
		int $withinSeconds = RedisJson::ACTIVE_WITHIN_DEFAULT,
	): ?int
	{
		return $this->jsonHandlerInstance()
			?->countActive($withinSeconds);
	}
	
	/**
	 * Garbage-collects the json handler's activity index (its documents
	 * and locks self-expire via redis TTLs; the php handler relies on the
	 * native machinery entirely - null); returns the removed count
	 */
	public function gc(): ?int
	{
		return $this->jsonHandlerInstance()
			?->gc();
	}
	
	/**
	 * The RediSearch index over the session documents (json handler with
	 * "session.index.enabled: yes"; RediSearch only indexes database 0)
	 */
	public function index(): ?Index
	{
		return $this->jsonHandlerInstance()
			?->index();
	}
	
	/**
	 * The historic by-reference accessor under the php handler
	 * (auto-vivifies missing keys with null); under json a SCALAR
	 * materializes as its value while a container or a missing value is
	 * a lazy Node (see RedisJson::peek), so legacy scalar reads keep
	 * working unchanged
	 */
	public function &__get(
		string $name,
	): mixed
	{
		$slot = &$this->store()
			->slot($name);
			
		return $slot;
	}
	
	public function __isset(
		string $name,
	): bool
	{
		return $this->store()
			->has([$name]);
	}
	
	public function __set(
		string $name,
		mixed $value,
	): void
	{
		$this->store()
			->set([$name], $value);
	}
	
	public function __unset(
		string $name,
	): void
	{
		$this->store()
			->remove([$name]);
	}
	
	/**
	 * Starts the json handler: connects the session redis, binds the
	 * cookie session id (minting one when needed) and registers the
	 * shutdown lock release
	 */
	protected function startJson(): void
	{
		if(($this->jsonHandler = $this->createJsonHandler()) === null)
		{
			throw new Exception('Session could not start.');
		}
		
		$sessionId = $this->readSessionId();
		if($sessionId === null)
		{
			$sessionId = $this->createSessionId();
			$this->sendCookie($sessionId);
		}
		
		$this->jsonHandler->open($sessionId);
		$this->store = new Json($this->jsonHandler);
		$this->started = true;
		
		// wake waiting parallel requests even when close() is never called
		register_shutdown_function(function(): void
		{
			$this->jsonHandler?->close();
		});
		
		// the navigation path timeline, opt-in via config
		if($this->sessionConfig->getPath(['journey', 'requests']) === true)
		{
			$this->jsonHandler->addRequest(
				(string)$this->request->getServer('REQUEST_METHOD'),
				(string)$this->request->getServer('REQUEST_URI'),
			);
		}
	}
	
	/**
	 * Builds a json storage handler from the session config;
	 * null when the session redis is unreachable
	 */
	protected function createJsonHandler(): ?RedisJson
	{
		if($this->sessionConfig->connection === null)
		{
			throw new MissingConfigException(
				'"connection" config section is missing.');
		}
		
		$connection = new Connection($this->sessionConfig->connection);
		if($connection->connect() === false)
		{
			return null;
		}
		
		// the queue (pub/sub) connection is connected on demand by the
		// MemoLock, only when a value lock is actually contended
		$queueConnection = new Connection($this->sessionConfig->connection);
		
		return new RedisJson(
			$connection,
			$queueConnection,
			(string)($this->sessionConfig->prefix ?? 'session'),
			$this->sessionConfig,
			$this,
		);
	}
	
	/**
	 * The started handler when available, otherwise a transient one:
	 * gc(), countActive() and index() are not bound to a session and
	 * must not START one either - minting an id and sending a cookie is
	 * no business of a monitoring call; the transient handler also
	 * serves the CLI, where no session ever starts
	 */
	protected function jsonHandlerInstance(): ?RedisJson
	{
		if($this->handler !== self::HANDLER_JSON)
		{
			return null;
		}
		
		return $this->jsonHandler
			?? ($this->transientJsonHandler ??= $this->createJsonHandler());
	}
	
	protected function readSessionId(): ?string
	{
		$sessionId = $_COOKIE[$this->cookieName()] ?? null;
		
		if(is_string($sessionId) === true
			&& preg_match(self::SESSION_ID_PATTERN, $sessionId) === 1)
		{
			return $sessionId;
		}
		
		return null;
	}
	
	protected function createSessionId(): string
	{
		return bin2hex(random_bytes(16));
	}
	
	protected function sendCookie(
		string $sessionId,
	): void
	{
		if(headers_sent() === true)
		{
			throw new Exception(
				'Session cookie could not be sent, headers already sent.');
		}
		
		$options = $this->cookieOptions();
		
		setcookie($this->cookieName(), $sessionId, [
			'expires' => $options['lifetime'] > 0
				? time() + $options['lifetime']
				: 0,
			'path' => (string)$options['path'],
			'domain' => (string)$options['domain'],
			'secure' => $options['secure'],
			'httponly' => $options['httponly'],
			'samesite' => (string)$options['samesite'],
		]);
	}
	
	protected function cookieName(): string
	{
		$name = (string)($this->sessionConfig->cookie_name
			?? ini_get('session.name')
			?: 'PHPSESSID');
		
		if($this->cookiesConfig->prefix)
		{
			$name = $this->cookiesConfig->prefix . $name;
		}
		
		return $name;
	}
	
	protected function cookieOptions(): array
	{
		$options = [
			'lifetime' => (int)ini_get('session.cookie_lifetime'),
			'path' => SYSTEM_PATH,
			'domain' => $this->app->getDomain(), // if we pass null here, then the domain will be set to the current domain
			'secure' => $this->request->isSecure(),
			'httponly' => true,
			'samesite' => $this->cookiesConfig->samesite,
		];
		// https://developer.mozilla.org/en-US/docs/Web/HTTP/Headers/Set-Cookie/SameSite
		// SameSite=None works only with Secure
		if($options['secure'] === false
			&& ($options['samesite'] === 'None' || $options['samesite'] === null))
		{
			$options['samesite'] = 'Lax';
		}
		
		return $options;
	}
	
	protected function path(
		string|array $path,
	): array
	{
		if(is_array($path) === true)
		{
			return $path;
		}
		
		// a dotted string is split - use the array form for keys
		// that themselves contain dots
		return explode('.', $path);
	}
	
	protected function initialize(): void
	{
		if($this->initialized === true)
		{
			return;
		}
		
		session_cache_limiter($this->sessionConfig->cache_limiter);
		
		$cookie = session_get_cookie_params();
		$options = $this->cookieOptions();
		$options['lifetime'] = $cookie['lifetime'];
		session_set_cookie_params($options);
		
		if($this->sessionConfig->cookie_name)
		{
			session_name($this->sessionConfig->cookie_name);
		}
		
		if($this->cookiesConfig->prefix)
		{
			session_name($this->cookiesConfig->prefix . session_name());
		}
		
		$this->initialized = true;
	}
}
