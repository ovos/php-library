<?php
declare(strict_types=1);

namespace Ovos\Cache\Store\KeyValue;

use Ovos\ArrayObject;
use Ovos\Cache\MemoLock\Redis as MemoLock;
use Ovos\Cache\Redis\Functions;
use Ovos\Connection\RedisCommon as Connection;
use Override;
use Redis as RedisClient;
use RedisCluster as RedisClusterClient;
use RedisClusterException;
use RedisException;

use function bin2hex;
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
