<?php
declare(strict_types=1);

namespace Ovos\Session\Handler;

use Ovos\ArrayObject;
use Ovos\Cache\MemoLock\Redis as MemoLock;
use Ovos\Cache\Prefixer;
use Ovos\Cache\Redis\Functions;
use Ovos\Connection\RedisCommon as Connection;
use Ovos\Exception;
use Ovos\Session\Index;
use Ovos\Session\Node;
use Ovos\Session\Store;
use ArrayObject as BaseArrayObject;
use Closure;
use JsonSerializable;
use stdClass;
use Throwable;
use Redis as RedisClient;
use RedisCluster as RedisClusterClient;

use function array_key_exists;
use function array_keys;
use function array_map;
use function array_pop;
use function array_values;
use function base64_decode;
use function base64_encode;
use function count;
use function get_object_vars;
use function implode;
use function in_array;
use function ini_get;
use function is_array;
use function is_int;
use function is_object;
use function is_string;
use function json_decode;
use function json_encode;
use function mb_check_encoding;
use function random_int;
use function serialize;
use function str_replace;
use function str_starts_with;
use function strlen;
use function substr;
use function time;
use function unserialize;

use const DIRECTORY_SEPARATOR;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * RedisJson
 *
 * Lazy session storage: one RedisJSON document per session, read and
 * written at nested paths, so nothing is loaded up front and parallel
 * requests sharing a session never block each other on a session-wide
 * lock. Writes are write-through - there is no request-end write-back,
 * mutating a fetched value does not persist it, set it back instead.
 *
 * Individual values are lockable: getLocked() takes a per-value MemoLock
 * lock, set() releases it and notifies the waiters; a plain get() on a
 * value locked by another request waits for that release. Writes honor
 * foreign locks server-side: the Lua functions check the lock keys
 * atomically with the write and refuse while one is held - the caller
 * waits for one release and retries (then unconditionally: availability
 * over strictness, the lock TTL bounds the wait).
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class RedisJson
{
	// Reserved document keys
	public const string KEY_META = '__meta';
	public const string KEY_JOURNEY = Store::KEY_JOURNEY;
	
	/**
	 * A leaf object that is not JSON-representable is stored as
	 * {"__php_serialized__": "<base64>"} and restored on read
	 */
	public const string KEY_SERIALIZED = '__php_serialized__';
	
	// Journey entries
	public const string JOURNEY_REQUEST = Store::JOURNEY_REQUEST;
	public const string JOURNEY_ACTION = Store::JOURNEY_ACTION;
	
	/**
	 * The reply marker of a write refused by a foreign value lock,
	 * followed by the 0-based index into the lock keys passed along
	 */
	public const string LOCKED = '__locked__:';
	
	// Key types (prefixed like the MemoLock lock/channel keys)
	public const string TYPE_ACTIVITY = 'activity';
	
	/**
	 * JSON.TYPE results that peek() materializes as plain values
	 */
	public const array SCALAR_TYPES = [
		'string',
		'integer',
		'number',
		'boolean',
		'null',
	];
	
	// Defaults
	public const int LIFETIME_DEFAULT = 1440;
	
	public const int JOURNEY_LIMIT_DEFAULT = 200;
	
	public const int ACTIVE_WITHIN_DEFAULT = 300;
	
	/**
	 * An array of function libraries used by this class
	 * (full path: the library lives here, not in the cache defaults)
	 */
	public const array LIBRARIES = [
		'session' => __DIR__ . DIRECTORY_SEPARATOR
			. '..' . DIRECTORY_SEPARATOR
			. 'Functions' . DIRECTORY_SEPARATOR . 'Session.lua',
	];
	
	protected Connection $connection;
	
	protected MemoLock $memoLock;
	
	protected Functions $functions;
	
	protected Prefixer $prefixer;
	
	protected ?ArrayObject $indexConfig = null;
	
	protected ?Index $index = null;
	
	protected ?string $sessionId = null;
	
	/**
	 * Session lifetime, sliding on every touched request
	 * Unit: seconds
	 */
	protected int $lifetime;
	
	/**
	 * Maximum kept journey entries (0 = unlimited)
	 */
	protected int $journeyLimit = self::JOURNEY_LIMIT_DEFAULT;
	
	/**
	 * The expiration slide and the activity stamp happen once per request
	 */
	protected bool $touched = false;
	
	/**
	 * Lock ids held by this request, released on set()/close()
	 */
	protected array $lockedPaths = [];
	
	public function __construct(
		Connection $connection,
		Connection $queueConnection,
		?string $prefix = null,
		?ArrayObject $config = null,
		?object $context = null,
	)
	{
		$this->connection = $connection;
		
		$this->prefixer = new Prefixer(
			$prefix,
		);
		
		$this->functions = new Functions(
			static::LIBRARIES,
			$connection,
			$prefix,
		);
		
		$lock = $config?->offsetGet('lock');
		$this->memoLock = new MemoLock(
			$connection,
			$queueConnection,
			$prefix,
			$lock !== null
				? new ArrayObject(['queue' => $lock])
				: null,
			$context,
		);
		
		$this->lifetime = (int)($config?->offsetGet('lifetime')
			?? (int)ini_get('session.gc_maxlifetime')
			?: self::LIFETIME_DEFAULT);
		
		if(($journey = $config?->offsetGet('journey')) !== null
			&& $journey->offsetGet('limit') !== null)
		{
			$this->journeyLimit = (int)$journey->offsetGet('limit');
		}
		
		$this->indexConfig = $config?->offsetGet('index');
	}
	
	/**
	 * Binds the handler to a session id - no I/O happens here
	 */
	public function open(
		string $sessionId,
	): static
	{
		$this->sessionId = $sessionId;
		
		return $this;
	}
	
	public function isOpen(): bool
	{
		return $this->sessionId !== null;
	}
	
	public function getSessionId(): ?string
	{
		return $this->sessionId;
	}
	
	public function getLifetime(): int
	{
		return $this->lifetime;
	}
	
	public function getMemoLock(): MemoLock
	{
		return $this->memoLock;
	}
	
	public function getClient(): RedisClient|RedisClusterClient|null
	{
		return $this->connection->getClient();
	}
	
	/**
	 * A lazy accessor object for a nested path - traversal is free,
	 * I/O happens only on its terminal calls
	 */
	public function node(
		array $path = [],
	): Node
	{
		return new Node($this, $path);
	}
	
	/**
	 * The RediSearch index over the session documents, when configured
	 * ("index.enabled" with an "index.fields" schema) - null otherwise
	 */
	public function index(): ?Index
	{
		if($this->indexConfig === null
			|| $this->indexConfig->offsetGet('enabled') !== true)
		{
			return null;
		}
		
		return $this->index ??= new Index(
			$this->connection,
			(string)$this->prefixer->getPrefix(),
			$this->indexConfig,
		);
	}
	
	/**
	 * The session document exists only after the first write
	 */
	public function exists(): bool
	{
		if(($client = $this->getClient()) === null)
		{
			return false;
		}
		
		return (int)$client->exists($this->key()) === 1;
	}
	
	/**
	 * Reads the value at a nested path - only this value travels over
	 * the wire; when the value is locked by ANOTHER request, waits for
	 * the lock holder to write (or the lock to expire) before reading
	 */
	public function get(
		array $path,
	): mixed
	{
		$this->ensureOpen();
		
		if($this->getClient() === null)
		{
			return null;
		}
		
		$this->touch();
		
		// one round trip: the hierarchical lock checks ride with the read
		return $this->decode(
			$this->checked($this->lockScope($path),
				fn($target): mixed => $target
					->rawCommand('JSON.GET', $this->key(), self::jsonPath($path)))
		);
	}
	
	/**
	 * Reads a value "the magic accessor way": a SCALAR materializes as
	 * itself (a type peek plus a read), while a container or a missing
	 * value stays a lazy Node - legacy code reading flags and ids through
	 * the magic accessors keeps working, and traversal never transfers a
	 * container payload
	 */
	public function peek(
		array $path,
	): mixed
	{
		$this->ensureOpen();
		
		if($this->getClient() === null)
		{
			return $this->node($path);
		}
		
		$this->touch();
		
		// the hierarchical lock checks ride with the type peek
		$types = $this->checked($this->lockScope($path),
			fn($target): mixed => $target
				->rawCommand('JSON.TYPE', $this->key(), self::jsonPath($path)));
				
		$type = is_array($types) === true
			? ($types[0] ?? null)
			: null;
		
		// containers and missing values stay lazy
		if($type === null
			|| in_array($type, self::SCALAR_TYPES, true) === false)
		{
			return $this->node($path);
		}
		
		return $this->read($path);
	}
	
	/**
	 * Reads several paths in ONE round trip (a multi-path JSON.GET) with
	 * the lock scopes of all paths checked alongside; the values are
	 * returned keyed by the dot-joined path
	 */
	public function getMany(
		array $paths,
	): array
	{
		$this->ensureOpen();
		
		if($paths === [])
		{
			return [];
		}
		
		if($this->getClient() === null)
		{
			return $this->decodeMany($paths, false);
		}
		
		$this->touch();
		
		// the union of every path's lock scope, deduplicated
		$lockIds = [];
		foreach($paths as $path)
		{
			foreach($this->lockScope($path) as $lockId)
			{
				$lockIds[$lockId] = $lockId;
			}
		}
		
		return $this->decodeMany($paths,
			$this->checked(array_values($lockIds),
				fn($target): mixed => $target->rawCommand('JSON.GET', $this->key(),
					...array_map(self::jsonPath(...), $paths)))
		);
	}
	
	/**
	 * Reads the value at a nested path and LOCKS it, announcing the
	 * intention to modify it within this request: parallel readers of
	 * this value wait until set() (or releaseLock()/close()) is called
	 */
	public function getLocked(
		array $path,
	): mixed
	{
		$this->ensureOpen();
		$this->touch();
		
		// a lock anywhere above this value covers it: wait for foreign
		// ancestor locks first (best-effort - the check and the acquisition
		// are not atomic; the lock TTLs bound the remaining races)
		$this->waitForForeignLocks($this->ancestorLockIds($path));
		
		$lockId = $this->lockId($path);
		
		$value = $this->memoLock->lockAndQueue($lockId,
			// never resolved by waiting - we want to end up holding the lock
			fetcher: static fn(): mixed => null,
			resolver: fn(): mixed => $this->read($path),
		);
		
		$this->lockedPaths[$lockId] = $path;
		
		return $value;
	}
	
	/**
	 * Locks the value, applies the updater to it and writes the result
	 * back (which releases the lock and wakes the waiters) - the safe
	 * form of read-modify-write: neither the lock nor the release can
	 * be forgotten, and an updater exception releases the lock too
	 */
	public function update(
		array $path,
		Closure $updater,
	): mixed
	{
		$value = $this->getLocked($path);
		
		try
		{
			$value = $updater($value);
		}
		catch(Throwable $throwable)
		{
			$this->releaseLock($path);
			
			throw $throwable;
		}
		
		$this->set($path, $value);
		
		return $value;
	}
	
	/**
	 * Writes the value at a nested path (creating missing parents) and
	 * releases the lock taken by getLocked(), waking any waiting readers;
	 * a value locked by ANOTHER request is only written after that lock's
	 * release (checked atomically with the write on the Lua side)
	 */
	public function set(
		array $path,
		mixed $value,
	): void
	{
		$this->ensureOpen();
		
		// a scalar root would leave a document no path can write into
		if($path === []
			&& is_array($value) === false
			&& $value instanceof BaseArrayObject === false)
		{
			throw new Exception(
				'The session document root must be an array.');
		}
		
		$this->touch();
		
		$this->write('session_set', $path, [
			$this->lifetime * 1000,
			time(),
			$this->encode($value),
			$this->encodePath($path),
		]);
		
		$this->releaseLock($path);
	}
	
	public function has(
		array $path,
	): bool
	{
		$this->ensureOpen();
		
		if(($client = $this->getClient()) === null)
		{
			return false;
		}
		
		$this->touch();
		
		$types = $client->rawCommand('JSON.TYPE',
			$this->key(), $this->jsonPath($path));
		
		return is_array($types) === true && count($types) > 0;
	}
	
	public function remove(
		array $path,
	): void
	{
		$this->ensureOpen();
		
		if(($client = $this->getClient()) === null)
		{
			return;
		}
		
		$this->touch();
		
		$client->rawCommand('JSON.DEL',
			$this->key(), $this->jsonPath($path));
		
		$this->releaseLock($path);
	}
	
	/**
	 * Atomically increments a numeric value (creating it when missing) -
	 * parallel-safe counters that need no explicit locking; a lock held
	 * by another request is honored the same way set() honors it, and a
	 * lock this request holds on the path is released
	 */
	public function increment(
		array $path,
		int|float $by = 1,
	): int|float|null
	{
		$this->ensureOpen();
		
		if(count($path) === 0)
		{
			throw new Exception(
				'The session document root cannot be incremented.');
		}
		
		$this->touch();
		
		$result = $this->write('session_increment', $path, [
			$this->lifetime * 1000,
			time(),
			$by,
			$this->encodePath($path),
		]);
		
		$this->releaseLock($path);
		
		if(is_string($result) === false)
		{
			return null;
		}
		
		return json_decode($result, true)[0] ?? null;
	}
	
	/**
	 * Atomically appends a value to an array at a nested path, creating
	 * the array (and missing parents) when necessary - lock-free list
	 * writes in one round trip; with a limit the array keeps only its
	 * last "limit" entries; returns the resulting array length
	 */
	public function append(
		array $path,
		mixed $value,
		int $limit = 0,
	): ?int
	{
		$this->ensureOpen();
		
		if(count($path) === 0)
		{
			throw new Exception(
				'The session document root cannot be appended to.');
		}
		
		$this->touch();
		
		$length = $this->write('session_append', $path, [
			$this->lifetime * 1000,
			time(),
			$limit,
			$this->encode($value),
			$this->encodePath($path),
		]);
		
		$this->releaseLock($path);
		
		return is_int($length) === true
			? $length
			: null;
	}
	
	/**
	 * The whole document, including the reserved "__meta"/"__journey" keys
	 */
	public function all(): array
	{
		return $this->get([]) ?? [];
	}
	
	/**
	 * Releases the lock taken by getLocked() without writing
	 */
	public function releaseLock(
		array $path,
	): bool
	{
		$lockId = $this->lockId($path);
		
		if(array_key_exists($lockId, $this->lockedPaths) === false)
		{
			return false;
		}
		
		unset($this->lockedPaths[$lockId]);
		
		return $this->memoLock->releaseActiveLock($lockId);
	}
	
	/**
	 * Releases every lock still held by this request, waking all waiters -
	 * called at the end of the request; an unreleased lock would only
	 * expire on its TTL and keep waiters blocked until then
	 */
	public function releaseLocks(): void
	{
		foreach(array_keys($this->lockedPaths) as $lockId)
		{
			unset($this->lockedPaths[$lockId]);
			
			$this->memoLock->releaseActiveLock($lockId);
		}
	}
	
	/**
	 * Records a manual user action (for example "ticket bought") into the
	 * journey timeline
	 */
	public function addAction(
		string $action,
		array $data = [],
	): void
	{
		$this->appendJourney([
			't' => time(),
			'type' => self::JOURNEY_ACTION,
			'action' => $action,
		] + ($data !== [] ? ['data' => $data] : []));
	}
	
	/**
	 * Records a request into the journey timeline (the navigation path)
	 */
	public function addRequest(
		string $method,
		string $url,
		array $data = [],
	): void
	{
		$this->appendJourney([
			't' => time(),
			'type' => self::JOURNEY_REQUEST,
			'method' => $method,
			'url' => $url,
		] + ($data !== [] ? ['data' => $data] : []));
	}
	
	/**
	 * The recorded requests and actions, oldest first - a timeline of the
	 * navigation path the user has taken
	 */
	public function getJourney(): array
	{
		return $this->get([self::KEY_JOURNEY]) ?? [];
	}
	
	/**
	 * How many sessions were active within the given time window - every
	 * touched request stamps its session into the activity sorted set
	 */
	public function countActive(
		int $withinSeconds = self::ACTIVE_WITHIN_DEFAULT,
	): int
	{
		if(($client = $this->getClient()) === null)
		{
			return 0;
		}
		
		$now = time();
		$activityKey = $this->activityKey();
		
		// expired sessions cannot be active - trim them while counting
		$results = $client->pipeline()
			->zRemRangeByScore($activityKey, '0', (string)($now - $this->lifetime))
			->zCount($activityKey, (string)($now - $withinSeconds), '+inf')
			->exec();
		
		return (int)($results[1] ?? 0);
	}
	
	/**
	 * Deterministic collection of the one thing that does not self-expire:
	 * stale members of the activity sorted set (documents and locks carry
	 * TTLs and are collected by redis itself) - safe to run from a cron,
	 * needs no bound session; returns the number of removed entries
	 */
	public function gc(): int
	{
		if(($client = $this->getClient()) === null)
		{
			return 0;
		}
		
		return (int)$client->zRemRangeByScore($this->activityKey(),
			'0', (string)(time() - $this->lifetime));
	}
	
	/**
	 * Deletes the session document
	 */
	public function destroy(): void
	{
		$this->ensureOpen();
		$this->releaseLocks();
		
		if(($client = $this->getClient()) === null)
		{
			return;
		}
		
		$client->pipeline()
			->unlink($this->key())
			->zRem($this->activityKey(), $this->sessionId)
			->exec();
	}
	
	/**
	 * Moves the document to a new session id (id regeneration); held
	 * locks are released first - they belong to the old id
	 * Returns false when there was no document to move (a session that
	 * was never written) - the id switch is still performed
	 */
	public function rename(
		string $newSessionId,
		bool $deleteOld = true,
	): bool
	{
		$this->ensureOpen();
		$this->releaseLocks();
		
		$oldSessionId = $this->sessionId;
		$oldKey = $this->key();
		$newKey = $this->prefixer->prefix($newSessionId);
		
		if($deleteOld === true)
		{
			$renamed = (int)$this->functions->call('session_rename',
				[$oldKey, $newKey],
				[$this->lifetime * 1000],
			) === 1;
		}
		else
		{
			$renamed = false;
			if(($client = $this->getClient()) !== null)
			{
				$renamed = (int)$client->rawCommand('COPY',
					$oldKey, $newKey) === 1;
				if($renamed === true)
				{
					$client->pExpire($newKey, $this->lifetime * 1000);
				}
			}
		}
		
		$this->sessionId = $newSessionId;
		
		if(($client = $this->getClient()) !== null)
		{
			$pipeline = $client->pipeline();
			if($deleteOld === true)
			{
				$pipeline->zRem($this->activityKey(), $oldSessionId);
			}
			$pipeline
				->zAdd($this->activityKey(), time(), $newSessionId)
				->exec();
		}
		
		return $renamed;
	}
	
	/**
	 * Ends the request's session work: releases all held locks
	 * There is deliberately no write-back - writes already happened
	 */
	public function close(): void
	{
		$this->releaseLocks();
	}
	
	/**
	 * Reads the value at a path without the lock check (used under an
	 * already-held lock and after a wait)
	 */
	protected function read(
		array $path,
	): mixed
	{
		if(($client = $this->getClient()) === null)
		{
			return null;
		}
		
		return $this->decode(
			$client->rawCommand('JSON.GET',
				$this->key(), self::jsonPath($path))
		);
	}
	
	/**
	 * Runs a read with the hierarchical lock checks riding in the same
	 * pipeline - one EXISTS per lock id in front of the payload command;
	 * when a lock held by ANOTHER request is found, waits for its release
	 * and re-runs the payload alone. Returns the payload's raw reply
	 */
	protected function checked(
		array $lockIds,
		Closure $payload,
	): mixed
	{
		if(($client = $this->getClient()) === null)
		{
			return false;
		}
		
		$pipeline = $client->pipeline();
		foreach($lockIds as $lockId)
		{
			$pipeline->exists($this->lockKey($lockId));
		}
		$payload($pipeline);
		$results = $pipeline->exec();
		
		if(($lockId = $this->foreignLock($lockIds, $results)) !== null)
		{
			$this->memoLock->waitForRelease($lockId);
			
			return $payload($client);
		}
		
		return $results[count($lockIds)] ?? false;
	}
	
	/**
	 * Calls a write function with the path's foreign-lock keys passed as
	 * extra KEYS - the Lua side refuses to write over a value locked by
	 * another request (the check and the write are atomic); one release
	 * is awaited, then the write is retried unconditionally: availability
	 * over strictness, the lock TTL bounds the wait
	 */
	protected function write(
		string $function,
		array $path,
		array $arguments,
	): mixed
	{
		$lockIds = [];
		$lockKeys = [];
		foreach($this->lockScope($path) as $lockId)
		{
			// own locks never block their holder
			if(array_key_exists($lockId, $this->lockedPaths) === true)
			{
				continue;
			}
			$lockIds[] = $lockId;
			$lockKeys[] = $this->lockKey($lockId);
		}
		
		$result = $this->functions->call($function,
			[$this->key(), ...$lockKeys],
			$arguments,
		);
		
		if(is_string($result) === true
			&& str_starts_with($result, self::LOCKED) === true)
		{
			$index = (int)substr($result, strlen(self::LOCKED));
			$this->memoLock->waitForRelease($lockIds[$index] ?? $lockIds[0]);
			
			// the holder had its chance - write unconditionally now
			$result = $this->functions->call($function,
				[$this->key()],
				$arguments,
			);
		}
		
		return $result;
	}
	
	/**
	 * The lock ids guarding a path, broadest first: the whole-session
	 * lock (the [] root), every ancestor, and the path itself - a lock
	 * taken anywhere above a value also covers it
	 */
	protected function lockScope(
		array $path,
	): array
	{
		$lockIds = [$this->lockId([])];
		
		$ancestor = [];
		foreach($path as $segment)
		{
			$ancestor[] = $segment;
			$lockIds[] = $this->lockId($ancestor);
		}
		
		return $lockIds;
	}
	
	protected function ancestorLockIds(
		array $path,
	): array
	{
		$lockIds = $this->lockScope($path);
		array_pop($lockIds); // the path's own lock is not an ancestor
		
		return $lockIds;
	}
	
	/**
	 * The first lock in the scope held by ANOTHER request, if any -
	 * expects one EXISTS reply per lock id at the start of the results
	 */
	protected function foreignLock(
		array $lockIds,
		array $results,
	): ?string
	{
		foreach($lockIds as $index => $lockId)
		{
			if((int)($results[$index] ?? 0) === 1
				&& array_key_exists($lockId, $this->lockedPaths) === false)
			{
				return $lockId;
			}
		}
		
		return null;
	}
	
	/**
	 * Waits for the broadest foreign lock among the given ids, if any
	 */
	protected function waitForForeignLocks(
		array $lockIds,
	): void
	{
		if($lockIds === [])
		{
			return;
		}
		
		$this->checked($lockIds, static fn($target): mixed => null);
	}
	
	/**
	 * Slides the session expiration and stamps the activity sorted set,
	 * once per request, riding on the first session operation
	 */
	protected function touch(): void
	{
		if($this->touched === true)
		{
			return;
		}
		
		if(($client = $this->getClient()) === null)
		{
			return;
		}
		
		$this->touched = true;
		
		$now = time();
		$pipeline = $client->pipeline()
			->pExpire($this->key(), $this->lifetime * 1000)
			->zAdd($this->activityKey(), $now, $this->sessionId);
		
		// opportunistic cleanup of long-gone sessions
		if(random_int(1, 100) === 100)
		{
			$pipeline->zRemRangeByScore($this->activityKey(),
				'0', (string)($now - $this->lifetime));
		}
		
		$pipeline->exec();
	}
	
	/**
	 * Appends a raw entry to the journey timeline, trimmed to the
	 * configured limit (addAction()/addRequest() build the usual shapes)
	 */
	public function appendJourney(
		array $entry,
	): void
	{
		$this->append([self::KEY_JOURNEY], $entry, $this->journeyLimit);
	}
	
	protected function ensureOpen(): void
	{
		if($this->sessionId === null)
		{
			throw new Exception(
				'Session handler is not bound to a session id, call open() first.');
		}
	}
	
	protected function key(): string
	{
		return $this->prefixer
			->prefix((string)$this->sessionId);
	}
	
	protected function activityKey(): string
	{
		return $this->prefixer
			->prefix(self::TYPE_ACTIVITY);
	}
	
	/**
	 * A value lock is scoped to the exact path within this session;
	 * MemoLock suffixes the id (":lock" / ":channel"), so basing it on the
	 * prefixed document key keeps all session keys under one prefix - the
	 * root lock id is the document key itself
	 */
	protected function lockId(
		array $path,
	): string
	{
		return $path === []
			? $this->key()
			: $this->key() . ':' . implode('.', $path);
	}
	
	/**
	 * The redis key of a value lock (the MemoLock prefixer puts the
	 * ":lock" type suffix last)
	 */
	protected function lockKey(
		string $lockId,
	): string
	{
		return $this->memoLock->getPrefixer()
			->prefix(MemoLock::TYPE_LOCK, $lockId);
	}
	
	/**
	 * RedisJSON bracket notation allows any character in a key; an
	 * INTEGER segment addresses a json array element instead - numeric
	 * STRINGS stay object keys, so only the array path form can carry
	 * real indices ("a.5.b" splits into strings)
	 */
	public static function jsonPath(
		array $path,
	): string
	{
		$jsonPath = '$';
		foreach($path as $segment)
		{
			if(is_int($segment) === true)
			{
				$jsonPath.= '[' . $segment . ']';
				
				continue;
			}
			
			$jsonPath.= '["'
				. str_replace(['\\', '"'], ['\\\\', '\\"'], (string)$segment)
				. '"]';
		}
		
		return $jsonPath;
	}
	
	protected function encode(
		mixed $value,
	): string
	{
		return json_encode($this->normalize($value),
			JSON_THROW_ON_ERROR
				| JSON_UNESCAPED_UNICODE
				| JSON_UNESCAPED_SLASHES,
		);
	}
	
	/**
	 * The path as ONE cjson argument for the write functions - a json
	 * array survives the wire with the number/string distinction intact
	 * (loose protocol arguments are stringified)
	 */
	protected function encodePath(
		array $path,
	): string
	{
		return json_encode(array_values($path),
			JSON_THROW_ON_ERROR
				| JSON_UNESCAPED_UNICODE
				| JSON_UNESCAPED_SLASHES,
		);
	}
	
	/**
	 * A "$"-path JSON.GET reply is a JSON array of matches
	 */
	protected function decode(
		mixed $raw,
	): mixed
	{
		if(is_string($raw) === false)
		{
			return null;
		}
		
		$matches = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
		
		return $this->denormalize($matches[0] ?? null);
	}
	
	/**
	 * A multi-path JSON.GET replies with an object keyed by the path
	 * strings (a single path with a bare match array)
	 */
	protected function decodeMany(
		array $paths,
		mixed $raw,
	): array
	{
		$values = [];
		
		if(is_string($raw) === false)
		{
			foreach($paths as $path)
			{
				$values[implode('.', $path)] = null;
			}
			
			return $values;
		}
		
		$decoded = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
		
		if(count($paths) === 1)
		{
			$values[implode('.', $paths[0])] = $this->denormalize(
				$decoded[0] ?? null);
			
			return $values;
		}
		
		foreach($paths as $path)
		{
			$matches = $decoded[$this->jsonPath($path)] ?? [];
			$values[implode('.', $path)] = $this->denormalize(
				$matches[0] ?? null);
		}
		
		return $values;
	}
	
	/**
	 * Prepares a PHP value for JSON storage: ArrayObjects become plain
	 * JSON objects (readable per-path, read back as arrays), any other
	 * non-JsonSerializable object becomes a serialized leaf marker
	 */
	protected function normalize(
		mixed $value,
	): mixed
	{
		if(is_array($value) === true)
		{
			return array_map($this->normalize(...), $value);
		}
		
		// json requires UTF-8 - a binary string round-trips as a leaf
		if(is_string($value) === true
			&& mb_check_encoding($value, 'UTF-8') === false)
		{
			return [
				self::KEY_SERIALIZED => base64_encode(serialize($value)),
			];
		}
		
		if(is_object($value) === false)
		{
			return $value;
		}
		
		if($value instanceof JsonSerializable)
		{
			return $value;
		}
		
		if($value instanceof stdClass)
		{
			// plain json data by nature (Model::export() and json_decode
			// both produce it) - stored addressable, read back as an
			// array, which Model::restore() takes as an iterable
			return array_map($this->normalize(...),
				get_object_vars($value));
		}
		
		if($value instanceof BaseArrayObject)
		{
			return array_map($this->normalize(...),
				$value->getArrayCopy());
		}
		
		return [
			self::KEY_SERIALIZED => base64_encode(serialize($value)),
		];
	}
	
	protected function denormalize(
		mixed $value,
	): mixed
	{
		if(is_array($value) === false)
		{
			return $value;
		}
		
		if(count($value) === 1
			&& is_string($value[self::KEY_SERIALIZED] ?? null) === true)
		{
			$serialized = base64_decode($value[self::KEY_SERIALIZED], true);
			$restored = $serialized === false
				? false
				: unserialize($serialized);
				
			// a corrupt leaf (or a user array that merely looks like one)
			// hands back the raw array instead of a silent false -
			// serialize(false) itself round-trips correctly
			if($restored === false && $serialized !== 'b:0;')
			{
				return $value;
			}
			
			return $restored;
		}
		
		return array_map($this->denormalize(...), $value);
	}
}
