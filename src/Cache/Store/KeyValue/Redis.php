<?php
declare(strict_types=1);

namespace Ovos\Cache\Store\KeyValue;

use LengthException;
use Ovos\ArrayObject;
use Ovos\Cache\MemoLock\Redis as MemoLock;
use Ovos\Cache\Redis\Functions;
use Ovos\Connection\RedisCommon as Connection;
use Override;
use Redis as RedisClient;
use RedisCluster as RedisClusterClient;
use RedisClusterException;
use RedisException;

use function array_key_exists;
use function array_keys;
use function array_values;
use function bin2hex;
use function count;
use function is_array;
use function is_int;
use function random_bytes;
use function sprintf;

/**
 * Redis
 *
 * @author Marcin Gil <mg@ovos.at>
 */
abstract class Redis extends Tags
{
	// Keys
	public const string KEY_DATA = 'data';
	public const string KEY_TAGS = 'tags';
	
	/**
	 * The invalidation guard's field: a tombstone holds it alone, and an item
	 * keeps it once its key was invalidated or written through (see
	 * KeyValue::rememberMiss())
	 */
	public const string KEY_EPOCH = 'epoch';
	
	/**
	 * A soft value's field: the tag-index stores keep its stale time (ms)
	 * there, so a tag invalidation marks the item instead of removing it
	 * (see TagIndex::KEY_INVALIDATED); the versioned stores whether a record
	 * is soft ('1' or '0')
	 */
	public const string KEY_SOFT = 'soft';
	
	/**
	 * What a write passes in place of an epoch when it is not guarded - no
	 * miss of this instance came before it (a write-through)
	 */
	public const string UNGUARDED = '*';
	
	/**
	 * Recorded as a miss's epoch when reading the item failed, so we do not know which epoch is stored. The
	 * key may have been invalidated without this process seeing it. No key ever has this value (an epoch is
	 * hex, see newEpoch()), but that alone does not refuse the write: the versioned stores' guarded write
	 * takes a key that holds no epoch at all. So set() refuses it itself, before any call (see isUnread()).
	 */
	public const string EPOCH_UNREAD = '-';
	
	/**
	 * Statuses
	 *
	 * Used for rawCommand, which returns strings instead of boolean values when OPT_REPLY_LITERAL is enabled
	 * @see https://github.com/phpredis/phpredis/issues/1550
	 */
	public const string STATUS_OK = 'OK';
	
	// Types
	public const string TYPE_ITEMS = 'items';
	
	// Libraries
	/**
	 * An array of function libraries used by this class
	 */
	public const array LIBRARIES = [
		'cache' => 'Cache.lua',
	];
	
	/**
	 * Redis connection
	 */
	protected Connection $connection;
	protected Connection $queueConnection;
	
	protected Functions $functions;
	
	protected int $multiMode = RedisClient::PIPELINE;
	
	public function __construct(
		Connection $connection,
		Connection $queueConnection,
		?string $prefix = null,
		?ArrayObject $config = null,
		?string $group = null,
	)
	{
		parent::__construct($prefix, $config, $group);
		
		$this->connection = $connection;
		$this->queueConnection = $queueConnection;
		
		$this->functions = new Functions(
			static::LIBRARIES,
			$connection,
			$prefix,
		);
		
		$this->configure($config);
	}
	
	public function getFunctions(): Functions
	{
		return $this->functions;
	}
	
	public function configure(
		?ArrayObject $config = null,
	): static
	{
		if($config === null)
		{
			return $this;
		}
		
		if($storeOptions = $config->offsetGet('store_options'))
		{
			$this->setStoreOptions($storeOptions);
		}
		
		return $this;
	}
	
	public function getConnection(): Connection
	{
		return $this->connection;
	}
	
	public function getQueueConnection(): Connection
	{
		return $this->queueConnection;
	}
	
	public function getClient(): RedisClient|RedisClusterClient|null
	{
		return $this->connection->getClient();
	}
	
	public function setStoreOptions(
		ArrayObject $options,
	): static
	{
		// the invalidation guard's window (see KeyValue::rememberMiss())
		if(($window = $options->offsetGet('invalidation_window_ms')) !== null)
		{
			$this->setInvalidationWindowMs((int)$window);
		}
		
		return $this;
	}
	
	public function getMemoLock(): MemoLock
	{
		// initialize the MemoLock on demand
		// (when there is no cache hit)
		if($this->memoLock === null)
		{
			// pub/sub requires a separate connection,
			// otherwise we will be getting "subscribe" & "unsubscribe"
			// messages on hGet
			$this->memoLock = new MemoLock(
				$this->connection,
				$this->queueConnection,
				$this->prefixer->getPrefix(),
				$this->config,
				$this,
			);
		}
		
		return $this->memoLock;
	}
	
	public function getType(
		string $type = self::TYPE_ITEMS,
	): string
	{
		return $this->prefixer
			->prefix($type, $this->getGroup());
	}
	
	#[Override]
	public function itemId(
		string $key,
	): string
	{
		return $this->prefixer
			->prefix($key, $this->getType());
	}
	
	/**
	 * Without a connection get() computes, a lockAndQueue() resolver runs
	 * without the lock
	 */
	#[Override]
	protected function isConnected(): bool
	{
		return $this->getClient() !== null;
	}
	
	/**
	 * Throws exception on purpose, this method is not meant to be used by normal users
	 */
	public function clear(): bool|int
	{
		if(($client = $this->getClient()) === null)
		{
			return false;
		}
		
		$group = $this->getGroup();
		$prefix = $this->prefixer
			->prefix('*', $group);
		$count = 0;
		
		$client->clearLastError();
		
		// the misses' stamp key goes with the wipe (cache_clear stamps it in the
		// same step): a write landing between a wipe and a separate stamp would
		// pass both
		$result = $this->functions->call('cache_clear', [], [
			$prefix,
			...$this->clearedStamp(),
		], long: true);
		
		if(is_int($result))
		{
			$count = $result;
		}
		
		if($error = $client->getLastError())
		{
			throw new RedisException($error);
		}
		
		return $count;
	}
	
	/**
	 * Replaces an item with its tombstone for the window - the key holds only
	 * "epoch" (see KeyValue::rememberMiss()), no data, so every reader takes
	 * it for a miss; the number of items that existed (0 or 1)
	 */
	protected function tombstone(
		string $id,
	): int
	{
		// this instance's own delete of the key is no race: a write it makes
		// after it is meant (update, delete, write the new value). Its own
		// clear and tag invalidation forget nothing - refusing is safe, and the
		// stamps and rules judge only what they invalidated
		unset($this->misses[$id]);
		
		$client = $this->getClient();
		$client?->clearLastError();
		$existed = $this->functions
			->call('cache_tombstone', [$id], [
				$this->newEpoch(),
				$this->invalidationWindowMs,
			]);
		if(is_int($existed))
		{
			return $existed;
		}
		
		// the call failed (a library that would not load, a writing script
		// refused under maxmemory, a node without it): the item goes anyway -
		// a delete that left it cached would be the stale value the guard
		// exists against - and the failure is logged
		$this->log(sprintf('cache_tombstone failed for "%s": %s',
			$id,
			$client?->getLastError() ?? 'no connection',
		));
		
		return $this->unlink($id);
	}
	
	/**
	 * Stores $value under $key. A null value or a negative TTL is a delete (see KeyValue::set()). A value
	 * nothing could check is not stored (see refuseUnjudged()). Any other value goes in one Lua call, with
	 * the release of the stampede lock (see writeWithRelease()).
	 */
	#[Override]
	public function set(
		string $key,
		mixed $value,
		int $ttl = 0,
		array $tags = [],
	): bool
	{
		if(($client = $this->getClient()) === null)
		{
			return false;
		}
		
		$id = $this->itemId($key);
		// The miss this write follows, if any. The write is refused when the key was deleted or invalidated
		// since that miss (see KeyValue::rememberMiss()).
		$miss = $this->writeMiss($id);
		
		// A negative TTL would expire the item at once, so it is a delete. So is a null value.
		if($ttl < 0 || $value === null)
		{
			try
			{
				return $this->delete($key);
			}
			finally
			{
				$this->getMemoLock()
					->releaseActiveLock($id);
			}
		}
		
		// The read failed, or on a versioned store no copy of the rules was at hand for it. The write could
		// only be refused, so it is not sent (see refuseUnjudged()).
		if($this->isUnread($miss)
			|| $this->isUnjudged($miss))
		{
			return $this->refuseUnjudged($key, $id, $miss);
		}
		
		try
		{
			$client->clearLastError();
			$result = $this->writeWithRelease($id, ...$this->writeCall($id, $key, $value, $ttl, $tags, $miss));
			// an error reply (OOM, WRONGTYPE, a read-only replica) answers false and throws nothing
			if($result === false && ($error = $client->getLastError()))
			{
				$this->log($error);
			}
			
			return (int)$result === 1;
		}
		catch(RedisException|RedisClusterException $exception)
		{
			$this->log($exception);
		}
		// The value is longer than the Compressor reads back, so it is not cached (see dropRefused()).
		catch(LengthException $exception)
		{
			$this->log($exception->getMessage());
			$this->dropRefused($key, $miss);
		}
		// the lock is released whatever happened; a second release does nothing
		finally
		{
			$this->getMemoLock()
				->debug('save: ' . $id)
				->releaseActiveLock($id);
		}
		
		return false;
	}
	
	/**
	 * Sends the write $function makes of $id ($keys: the call's keys) - with
	 * the release of the lock this process holds for $id, if any, in the same
	 * round trip (a standalone client): its waiters are told as the value
	 * lands. A write whose reply is an error goes again through call(), which
	 * self-heals a library gone; a release whose reply is one is left to
	 * releaseActiveLock() (see set())
	 *
	 * @param list<string> $keys
	 * @param list<mixed> $args
	 */
	protected function writeWithRelease(
		string $id,
		string $function,
		array $keys,
		array $args,
	): mixed
	{
		// a lock is held only where a miss made the MemoLock - a write-through
		// makes none
		$memoLock = $this->memoLock;
		$release = $memoLock instanceof MemoLock
			? $memoLock->releaseCall($id)
			: null;
		$replies = $release === null
			? null
			: $this->sendAll([
				[$this->functions->functionsPrefix($function), $keys, $args],
				$release,
			]);
		if($replies === null)
		{
			return $this->functions
				->call($function, $keys, $args);
		}
		
		if(($replies[1] ?? false) !== false)
		{
			$memoLock->released($id);
		}
		
		return ($replies[0] ?? false) === false
			? $this->functions->call($function, $keys, $args)
			: $replies[0];
	}
	
	/**
	 * Reads an item with one HMGET of fetchedFields() and leaves the reply to judge(). The data is read
	 * outside any Lua script, in the one round trip a read makes.
	 */
	#[Override]
	protected function fetch(
		string $id,
	): mixed
	{
		if(($client = $this->getClient()) === null)
		{
			return null;
		}
		
		try
		{
			$item = $client->hMGet($id, $this->fetchedFields());
		}
		// RedisClusterException does not extend RedisException, catch both. Redis did not answer: a miss,
		// and no write may follow it (see rememberUnread()).
		catch(RedisException|RedisClusterException $exception)
		{
			$this->log($exception);
			$this->rememberUnread($id);
			
			return null;
		}
		
		// an error reply (BUSY, LOADING) is a failed read too (see failedReply())
		if(is_array($item) === false)
		{
			$this->failedReply($id);
			
			return null;
		}
		
		return $this->judge($id, $item);
	}
	
	/**
	 * The fields fetch() reads with its HMGET
	 *
	 * @return list<string>
	 */
	abstract protected function fetchedFields(): array;
	
	/**
	 * fetch()'s verdict on what its HMGET read ($item: the reply)
	 */
	abstract protected function judge(
		string $id,
		mixed $item,
	): mixed;
	
	/**
	 * The write of $value under $key ($id) as set() sends it, guarded by
	 * $miss (the miss it follows, or null: a write-through) - [function,
	 * keys, args] of its Lua call
	 *
	 * @return array{0: string, 1: list<string>, 2: list<mixed>}
	 */
	abstract protected function writeCall(
		string $id,
		string $key,
		mixed $value,
		int $ttl,
		array $tags,
		?array $miss,
	): array;
	
	/**
	 * The miss a write of $id follows (see KeyValue::takeMiss())
	 */
	protected function writeMiss(
		string $id,
	): ?array
	{
		return $this->takeMiss($id);
	}
	
	/**
	 * Reads several items in one round trip on a standalone client: a pipeline of fetch()'s HMGET, each
	 * reply checked as the item at its place in the pipeline. On a cluster: one by one, because the items
	 * are in different slots.
	 */
	#[Override]
	protected function fetchMany(
		array $ids,
	): array
	{
		if($ids === [])
		{
			return [];
		}
		
		if(($client = $this->getClient()) instanceof RedisClient === false)
		{
			return parent::fetchMany($ids);
		}
		
		$found = [];
		try
		{
			$pipeline = $client->pipeline();
			foreach($ids as $id)
			{
				$pipeline->hMGet($id, $this->fetchedFields());
			}
			$replies = $pipeline->exec();
			$replies = is_array($replies) ? $replies : [];
			
			// a reply that is a hash is checked as fetch() checks it; any other reply is a failed read
			$judged = [];
			$read = [];
			$failed = [];
			foreach(array_values($ids) as $index => $id)
			{
				if(is_array($replies[$index] ?? false))
				{
					$judged[] = $id;
					$read[] = $replies[$index];
					
					continue;
				}
				
				$failed[] = $id;
			}
			$found = $this->judgeMany($judged, $read);
			foreach($failed as $id)
			{
				$this->failedReply($id);
				$found[$id] = null;
			}
		}
		catch(RedisException $exception)
		{
			$this->log($exception);
		}
		
		// An item the read did not reach is a miss, as in fetch(), and no write may follow it (see
		// rememberUnread()).
		foreach($ids as $id)
		{
			if(array_key_exists($id, $found) === false)
			{
				$this->rememberUnread($id);
				$found[$id] = null;
			}
		}
		
		return $found;
	}
	
	/**
	 * Remembers a miss whose read failed (see EPOCH_UNREAD). The key may have been deleted or invalidated
	 * without this process seeing it, so no write may follow (see refuseUnjudged()). The exception is a
	 * second read of the key before the write, made together with the lock when the queue is on.
	 */
	protected function rememberUnread(
		string $id,
	): void
	{
		$this->rememberMiss($id, static::EPOCH_UNREAD);
	}
	
	/**
	 * A read whose reply is not a hash. An error reply (BUSY, LOADING, MASTERDOWN) answers false and throws
	 * nothing, but it is a failed read all the same (see rememberUnread()).
	 */
	protected function failedReply(
		string $id,
	): void
	{
		$this->log(sprintf('Cache read of "%s" failed: %s',
			$id,
			$this->getClient()?->getLastError() ?? 'no reply',
		));
		$this->rememberUnread($id);
	}
	
	/**
	 * Whether the read of $miss failed (see rememberUnread()). Checked in PHP, before any call: a versioned
	 * store's guarded write would take the marker on a key without an epoch (see EPOCH_UNREAD). The
	 * tag-index stores take no miss older than the guard window (see writeMiss()); a versioned store takes
	 * it, so there this holds past the window too, and the write after it is refused once.
	 *
	 * @param array{epoch: string}|null $miss
	 */
	protected function isUnread(
		?array $miss,
	): bool
	{
		return $miss !== null
			&& $miss['epoch'] === static::EPOCH_UNREAD;
	}
	
	/**
	 * Handles the key of a write that was not made: one nothing could check (see refuseUnjudged()), or a
	 * value longer than the Compressor reads back. The key is deleted, so the value it was meant to replace
	 * is not served, and the next reader computes. A versioned store leaves the key after its own
	 * computation (see RedisVersioned::dropRefused()).
	 *
	 * @param array{computing?: bool}|null $miss
	 */
	protected function dropRefused(
		string $key,
		?array $miss,
	): void
	{
		$this->delete($key);
	}
	
	/**
	 * Whether the value written after $miss cannot be checked. Never here: only the versioned stores need
	 * a copy of their rules to check an item (see RedisVersioned::isUnjudged()).
	 *
	 * @param array{epoch: string, stamp: ?string}|null $miss
	 */
	protected function isUnjudged(
		?array $miss,
	): bool
	{
		return false;
	}
	
	/**
	 * Used instead of the write after a miss whose value nothing could check: the read failed, or, on a
	 * versioned store, no copy of the rules was at hand. That write could only be refused, so nothing is
	 * stored. The value the read did not see may be the one the caller replaces (a forced refresh, a set()
	 * after peek() or after a read-only get()), so dropRefused() handles the key. Returns false, as for a
	 * refused write, and releases the lock of $id.
	 */
	protected function refuseUnjudged(
		string $key,
		string $id,
		?array $miss,
	): bool
	{
		try
		{
			$this->dropRefused($key, $miss);
		}
		// the lock is released whatever happened
		finally
		{
			$this->getMemoLock()
				->releaseActiveLock($id);
		}
		
		return false;
	}
	
	/**
	 * Checks a batch's HMGET replies as judge() checks one, and returns the values by id. $replies are in
	 * the order of $ids.
	 *
	 * @param list<string> $ids
	 * @param list<mixed> $replies
	 * @return array<string, mixed>
	 */
	protected function judgeMany(
		array $ids,
		array $replies,
	): array
	{
		$found = [];
		foreach($ids as $index => $id)
		{
			$found[$id] = $this->judge($id, $replies[$index] ?? false);
		}
		
		return $found;
	}
	
	/**
	 * Stores what a batch computed in one round trip on a standalone client:
	 * every write as set() sends it, each with the release of the lock this
	 * process holds for its key (see writeWithRelease()). A write whose reply
	 * is an error goes again through call(), which self-heals a library gone;
	 * a cluster (or no client): one set() per key
	 */
	#[Override]
	protected function storeMany(
		array $entries,
	): array
	{
		if($this->getClient() instanceof RedisClient === false)
		{
			return parent::storeMany($entries);
		}
		
		$memoLock = $this->memoLock instanceof MemoLock
			? $this->memoLock
			: null;
		$stored = [];
		$calls = [];
		// by id: the write's call, its place in $calls, its release's
		$writes = [];
		
		try
		{
			foreach($entries as $id => [$key, $value, $ttl, $tags])
			{
				$miss = $this->writeMiss($id);
				// a negative TTL or a null value: a delete it is (see set())
				if($ttl < 0 || $value === null)
				{
					$stored[$key] = $this->delete($key);
					
					continue;
				}
				// nothing could check the value, so it is not stored (see refuseUnjudged())
				if($this->isUnread($miss)
					|| $this->isUnjudged($miss))
				{
					$this->dropRefused($key, $miss);
					$stored[$key] = false;
					
					continue;
				}
				
				// a value longer than the Compressor reads back is not cached (see dropRefused())
				try
				{
					$call = $this->writeCall($id, $key, $value, $ttl, $tags, $miss);
				}
				catch(LengthException $exception)
				{
					$this->log($exception->getMessage());
					$this->dropRefused($key, $miss);
					$stored[$key] = false;
					
					continue;
				}
				$writes[$id] = [$key, $call, count($calls), null];
				$calls[] = [$this->functions->functionsPrefix($call[0]), $call[1], $call[2]];
				if(($release = $memoLock?->releaseCall($id)) !== null)
				{
					$writes[$id][3] = count($calls);
					$calls[] = $release;
				}
			}
			
			$replies = $calls === []
				? []
				: $this->sendAll($calls);
			foreach($writes as $id => [$key, [$function, $keys, $args], $at, $releaseAt])
			{
				if($replies !== null
					&& $releaseAt !== null
					&& ($replies[$releaseAt] ?? false) !== false)
				{
					$memoLock->released($id);
				}
				
				$result = $replies === null || ($replies[$at] ?? false) === false
					? $this->functions->call($function, $keys, $args)
					: $replies[$at];
				$stored[$key] = (int)$result === 1;
			}
		}
		catch(RedisException $exception)
		{
			$this->log($exception);
		}
		finally
		{
			// a lock no write released goes now - a refused write's too
			$memoLock?->releaseMany(array_keys($entries));
		}
		
		return $stored;
	}
	
	/**
	 * Several calls in one round trip (see Functions::callAll())
	 *
	 * @param list<array{0: string, 1: list<string>, 2: list<mixed>}> $calls
	 * @return list<mixed>|null
	 */
	protected function sendAll(
		array $calls,
	): ?array
	{
		return $this->functions
			->callAll($calls);
	}
	
	/**
	 * A plain removal - the fallback when a guarded one failed
	 */
	protected function unlink(
		string $id,
	): int
	{
		try
		{
			return (int)$this->getClient()?->unlink($id);
		}
		catch(RedisException|RedisClusterException $exception)
		{
			$this->log($exception);
		}
		
		return 0;
	}
	
	/**
	 * The epoch a write-through brings: it marks the key, so a recomputation
	 * whose miss came before it is refused (none when the guard is off)
	 */
	protected function writeThroughEpoch(): string
	{
		return $this->isGuarded() ? $this->newEpoch() : '';
	}
	
	/**
	 * What cache_clear stamps after its wipe: [key, window] for the tag-index
	 * stores' "cleared" stamp, nothing for the others
	 *
	 * @return list<string|int>
	 */
	protected function clearedStamp(): array
	{
		return [];
	}
	
	/**
	 * A fresh token for a tombstone
	 */
	protected function newEpoch(): string
	{
		return bin2hex(random_bytes(8));
	}
	
	/**
	 * Logs a call the server refused: an error reply (WRONGTYPE, OOM, a
	 * read-only replica) answers false, it throws nothing - an invalidation
	 * lost that way would go unnoticed
	 */
	protected function logRefused(
		string $function,
	): void
	{
		$this->log(new RedisException(sprintf('Cache call %s was refused: %s',
			$function,
			$this->getClient()?->getLastError() ?? 'no reply',
		)));
	}
	
	/**
	 * Logs events (messages/errors/exceptions)
	 */
	#[Override]
	public function log(
		...$event,
	): static
	{
		$this->connection->log(...$event);
		
		return $this;
	}
}
