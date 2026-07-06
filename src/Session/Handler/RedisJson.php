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
use ArrayObject as BaseArrayObject;
use Closure;
use JsonSerializable;
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
 * value locked by another request waits for that release.
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class RedisJson
{
	// Reserved document keys
	public const string KEY_META = '__meta';
	public const string KEY_JOURNEY = '__journey';
	
	/**
	 * A leaf object that is not JSON-representable is stored as
	 * {"__php_serialized__": "<base64>"} and restored on read
	 */
	public const string KEY_SERIALIZED = '__php_serialized__';
	
	// Journey entries
	public const string JOURNEY_REQUEST = 'request';
	public const string JOURNEY_ACTION = 'action';
	
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
		
		if(($client = $this->getClient()) === null)
		{
			return null;
		}
		
		$this->touch();
		
		// one round trip: the hierarchical lock checks ride with the read
		$lockIds = $this->lockScope($path);
		$pipeline = $client->pipeline();
		foreach($lockIds as $lockId)
		{
			$pipeline->exists($this->memoLock->getPrefixer()
				->prefix(MemoLock::TYPE_LOCK, $lockId));
		}
		$results = $pipeline
			->rawCommand('JSON.GET', $this->key(), $this->jsonPath($path))
			->exec();
		
		// locked by another request (own locks are tracked locally):
		// wait for the release publication, then re-read
		if(($lockId = $this->foreignLock($lockIds, $results)) !== null)
		{
			$this->memoLock->waitForRelease($lockId);
			
			return $this->read($path);
		}
		
		return $this->decode($results[count($lockIds)] ?? false);
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
		
		if(($client = $this->getClient()) === null)
		{
			return $this->node($path);
		}
		
		$this->touch();
		
		// the hierarchical lock checks ride with the type peek
		$lockIds = $this->lockScope($path);
		$pipeline = $client->pipeline();
		foreach($lockIds as $lockId)
		{
			$pipeline->exists($this->memoLock->getPrefixer()
				->prefix(MemoLock::TYPE_LOCK, $lockId));
		}
		$results = $pipeline
			->rawCommand('JSON.TYPE', $this->key(), $this->jsonPath($path))
			->exec();
		
		if(($lockId = $this->foreignLock($lockIds, $results)) !== null)
		{
			$this->memoLock->waitForRelease($lockId);
			
			$types = $client->rawCommand('JSON.TYPE',
				$this->key(), $this->jsonPath($path));
		}
		else
		{
			$types = $results[count($lockIds)] ?? null;
		}
		
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
		
		if(($client = $this->getClient()) === null)
		{
			$values = [];
			foreach($paths as $path)
			{
				$values[implode('.', $path)] = null;
			}
			
			return $values;
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
		$lockIds = array_values($lockIds);
		
		$pipeline = $client->pipeline();
		foreach($lockIds as $lockId)
		{
			$pipeline->exists($this->memoLock->getPrefixer()
				->prefix(MemoLock::TYPE_LOCK, $lockId));
		}
		$results = $pipeline
			->rawCommand('JSON.GET', $this->key(),
				...array_map($this->jsonPath(...), $paths))
			->exec();
		
		if(($lockId = $this->foreignLock($lockIds, $results)) !== null)
		{
			$this->memoLock->waitForRelease($lockId);
			
			return $this->readMany($paths);
		}
		
		return $this->decodeMany($paths, $results[count($lockIds)] ?? false);
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
	 * releases the lock taken by getLocked(), waking any waiting readers
	 */
	public function set(
		array $path,
		mixed $value,
	): void
	{
		$this->ensureOpen();
		$this->touch();
		
		$this->functions->call('session_set',
			[$this->key()],
			[
				$this->lifetime * 1000,
				time(),
				$this->encode($value),
				...$path,
			],
		);
		
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
	 * parallel-safe counters without any locking
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
		
		$result = $this->functions->call('session_increment',
			[$this->key()],
			[
				$this->lifetime * 1000,
				time(),
				$by,
				...$path,
			],
		);
		
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
		
		$length = $this->functions->call('session_append',
			[$this->key()],
			[
				$this->lifetime * 1000,
				time(),
				$limit,
				$this->encode($value),
				...$path,
			],
		);
		
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
				$this->key(), $this->jsonPath($path))
		);
	}
	
	/**
	 * Reads several paths without the lock checks (used after a wait)
	 */
	protected function readMany(
		array $paths,
	): array
	{
		if(($client = $this->getClient()) === null)
		{
			$values = [];
			foreach($paths as $path)
			{
				$values[implode('.', $path)] = null;
			}
			
			return $values;
		}
		
		return $this->decodeMany($paths,
			$client->rawCommand('JSON.GET', $this->key(),
				...array_map($this->jsonPath(...), $paths))
		);
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
		if($lockIds === [] || ($client = $this->getClient()) === null)
		{
			return;
		}
		
		$pipeline = $client->pipeline();
		foreach($lockIds as $lockId)
		{
			$pipeline->exists($this->memoLock->getPrefixer()
				->prefix(MemoLock::TYPE_LOCK, $lockId));
		}
		$results = $pipeline->exec();
		
		if(($lockId = $this->foreignLock($lockIds, $results)) !== null)
		{
			$this->memoLock->waitForRelease($lockId);
		}
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
	
	protected function appendJourney(
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
	 * prefixed document key keeps all session keys under one prefix
	 */
	protected function lockId(
		array $path,
	): string
	{
		return $this->key() . ':' . implode('.', $path);
	}
	
	/**
	 * RedisJSON bracket notation allows any character in a key
	 */
	public static function jsonPath(
		array $path,
	): string
	{
		$jsonPath = '$';
		foreach($path as $segment)
		{
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
			return unserialize(
				base64_decode($value[self::KEY_SERIALIZED]));
		}
		
		return array_map($this->denormalize(...), $value);
	}
}
