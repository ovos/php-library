<?php
declare(strict_types=1);

namespace Ovos\Cache\Store\KeyValue;

use Ovos\ArrayObject;
use Ovos\Cache\MemoLock\Redis as MemoLock;
use Ovos\Cache\Redis\Functions;
use Ovos\Cache\Stale;
use Ovos\Connection\RedisCommon as Connection;
use Override;
use Closure;
use Redis as RedisClient;
use RedisCluster as RedisClusterClient;
use RedisClusterException;
use RedisException;

use function bin2hex;
use function is_array;
use function is_int;
use function random_bytes;
use function sprintf;
use function str_pad;

use const STR_PAD_LEFT;

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
	 * Soft invalidation on the tag-index stores (Store\Redis, Redisearch): a
	 * soft value's item carries its stale time (ms); a tag invalidation then
	 * marks it - a new epoch, this field, both expiring with the window -
	 * instead of tombstoning it, and a read inside the window serves it aged
	 */
	public const string KEY_SOFT = 'soft';
	
	public const string KEY_INVALIDATED = 'invalidated';
	
	/**
	 * What a write passes in place of an epoch when it is not guarded - no
	 * miss of this instance came before it (a write-through)
	 */
	public const string UNGUARDED = '*';
	
	/**
	 * Statuses
	 *
	 * Used for rawCommand, which returns strings instead of boolean values when OPT_REPLY_LITERAL is enabled
	 * @see https://github.com/phpredis/phpredis/issues/1550
	 */
	public const string STATUS_OK = 'OK';
	
	// Types
	public const string TYPE_ITEMS = 'items';
	
	/**
	 * The stamps the tag-index stores (Store\Redis, Redisearch) leave for the
	 * window: one per invalidated tag, and the type key itself for a clear
	 */
	public const string TYPE_INVALIDATED = 'invalidated';
	
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
		
		$this->setConnection($connection);
		$this->setQueueConnection($queueConnection);
		
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
	
	public function setConnection(
		Connection $connection,
	): static
	{
		$this->connection = $connection;
		
		return $this;
	}
	
	public function getConnection(): Connection
	{
		return $this->connection;
	}
	
	public function setQueueConnection(
		Connection $connection,
	): static
	{
		$this->queueConnection = $connection;
		
		return $this;
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
			// the data, the guard's epoch and a soft invalidation's mark in the
			// one round trip a read makes
			$item = $client->hMGet($id, [
				static::KEY_DATA,
				static::KEY_EPOCH,
				static::KEY_INVALIDATED,
			]);
			$value = is_array($item) ? ($item[static::KEY_DATA] ?? false) : false;
			
			if($value !== false)
			{
				$value = $this->compressor
					->decompress($value);
				$value = $this->serializer
					->unserialize($value);
				
				// softly invalidated, inside its window (the mark and the data
				// expire with it): the value is handed out aged - served while
				// it is refreshed, a miss to a read without stale: (only a soft
				// value is ever marked; anything else marked is a miss)
				if(($item[static::KEY_INVALIDATED] ?? false) !== false)
				{
					$value = $value instanceof Stale
						? $value->aged()
						: null;
				}
				
				if($value !== null)
				{
					return $this->found($id, $value, $item[static::KEY_EPOCH]);
				}
			}
			
			$this->rememberMiss($id, is_array($item) ? $item[static::KEY_EPOCH] : false);
		}
		// RedisClusterException does not extend RedisException, catch both
		catch(RedisException|RedisClusterException $exception)
		{
			$this->log($exception);
		}
		
		return null;
	}
	
	#[Override]
	public function itemId(
		string $key,
	): string
	{
		return $this->prefixer
			->prefix($key, $this->getType());
	}
	
	#[Override]
	public function get(
		string $key,
		?Closure $resolver = null,
		int $ttl = 0,
		array $tags = [],
		?bool $queue = null, // override of the config switch
		?int $queueLockTtlMs = null, // override of the config value
		int $stale = 0, // seconds past the ttl a value is served while it is refreshed
		bool $soft = false, // ... and past a tag invalidation (soft invalidation)
	): mixed
	{
		if($this->getClient() === null)
		{
			// no connection (fast path)
			return $this->setFromResolver($key, $resolver, $ttl, $tags, $stale, $soft);
		}
		
		$id = $this->prefixer
			->prefix($key, $this->getType());
		
		// initial hit check (fast path); past its ttl, a value written with a
		// stale time is served while it is refreshed (see revalidate())
		$refresh = $stale > 0 && $resolver !== null
			? fn() => $this->setFromResolver($key, $resolver, $ttl, $tags, $stale, $soft)
			: null;
		if(($data = $this->served($id, $this->fetch($id), $refresh)) !== null)
		{
			return $data;
		}
		
		return $this->getMemoLock()
			->lockAndQueue(
				$id,
				fn() => $this->fresh($this->fetch($id)),
				function() use ($id, $key, $resolver, $ttl, $tags, $stale, $soft): mixed
				{
					// the miss is stamped now, right before the value is computed
					// (the caller's own computation, too, when there is no resolver)
					$this->stampMiss($id);
					
					return $this->setFromResolver($key, $resolver, $ttl, $tags, $stale, $soft);
				},
				$queue,
				$queueLockTtlMs,
			);
	}
	
	/**
	 * Manual lock control: returns the cached value when another process
	 * produced it meanwhile (this one holds NO lock then), otherwise what the
	 * resolver returns - null without one - while this process holds the
	 * lock: compute, then set() (or releaseActiveLock()). The cache is read
	 * after waiting and once more right after the lock is taken, as in get().
	 * A key that is never set (a critical section) reads as nothing, so every
	 * caller takes the lock in turn
	 */
	public function lockAndQueue(
		string $key,
		?Closure $resolver = null,
		?int $queueLockTtlMs = null, // override of the config value
	): mixed
	{
		if($this->getClient() === null)
		{
			// no connection (fast path)
			return $this->invoker
				->invoke($resolver);
		}
		
		$id = $this->prefixer
			->prefix($key, $this->getType());
		
		return $this->getMemoLock()
			->lockAndQueue(
				$id,
				fn() => $this->fresh($this->fetch($id)),
				function() use ($id, $resolver): mixed
				{
					// the miss is stamped now, right before this process
					// computes (see get())
					$this->stampMiss($id);
					
					return $this->invoker
						->invoke($resolver);
				},
				true,
				$queueLockTtlMs,
			);
	}
	
	public function releaseActiveLock(
		string $key,
	): bool
	{
		$id = $this->prefixer
			->prefix($key, $this->getType());
		
		return $this->getMemoLock()
			->releaseActiveLock($id);
	}
	
	public function renewLock(
		string $key,
	): bool
	{
		$id = $this->prefixer
			->prefix($key, $this->getType());
		
		return $this->getMemoLock()
			->renewLock($id);
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
	 * Reclaims whatever a store accumulates that does not expire on its own.
	 * The default is a no-op: items carry a TTL and there is no side index to
	 * sweep. The tag-hash store overrides this to prune dangling tag -> id
	 * references; the versioned stores keep the default (their rules stream
	 * self-trims and stale items expire by TTL). Defined here so the cache
	 * maintenance cron can call collectGarbage() on any persistent store.
	 */
	public function collectGarbage(): bool|int
	{
		return true;
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
	 * The server clock in microseconds - the time a miss stamps in the
	 * tag-index stores, read from the server whose clock their tag stamps
	 * use; null when it cannot be read
	 */
	protected function serverTime(): ?string
	{
		// a cluster's TIME needs a node: the tag-index stores run standalone
		// only (their Lua reaches keys of any slot)
		if(($client = $this->getClient()) === null
			|| $client instanceof RedisClusterClient)
		{
			return null;
		}
		
		try
		{
			$time = $client->time();
			if(is_array($time) && isset($time[0], $time[1]))
			{
				return $time[0] . str_pad((string)$time[1], 6, '0', STR_PAD_LEFT);
			}
		}
		catch(RedisException|RedisClusterException $exception)
		{
			$this->log($exception);
		}
		
		return null;
	}
	
	/**
	 * The key of an invalidation stamp: one tag's, or (no tag) the store's
	 * "cleared" stamp
	 */
	public function stampKey(
		?string $tag = null,
	): string
	{
		$type = $this->getType(static::TYPE_INVALIDATED);
		
		return $tag === null
			? $type
			: $this->prefixer->prefix($tag, $type);
	}
	
	/**
	 * Stamps the given tags (none: the store's "cleared" stamp) with the
	 * server time, for the window - a guarded write whose miss came before
	 * refuses
	 */
	protected function stamp(
		array $tags = [],
	): bool
	{
		if($this->isGuarded() === false)
		{
			return true;
		}
		
		$keys = [];
		foreach($tags as $tag)
		{
			$keys[] = $this->stampKey((string)$tag);
		}
		if($keys === [])
		{
			$keys[] = $this->stampKey();
		}
		
		return $this->functions
			->call('cache_stamp', $keys, [
				$this->invalidationWindowMs,
			]) !== false;
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
