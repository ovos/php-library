<?php
declare(strict_types=1);

namespace Ovos\Cache\Store\KeyValue;

use Ovos\Cache\Stale;
use Closure;
use Override;
use Redis as RedisClient;
use RedisCluster as RedisClusterClient;
use RedisClusterException;
use RedisException;

use function is_array;
use function str_pad;

use const STR_PAD_LEFT;

/**
 * TagIndex
 *
 * The tag-index stores (Store\Redis, Redisearch): an index finds the items a
 * tag invalidation reaches, and the invalidation guard covers a value being
 * computed meanwhile with stamps - one per invalidated tag, one for a clear -
 * that a write whose miss came before refuses, the miss stamped with the
 * server time they are compared with; a soft value is marked, not removed.
 * The versioned stores need none of it: their rules do all of that
 *
 * @author Marcin Gil <mg@ovos.at>
 */
abstract class TagIndex extends Redis
{
	/**
	 * A softly invalidated item's mark: a tag invalidation sets it (with a
	 * new epoch, both expiring with the window) on an item carrying its stale
	 * time (KEY_SOFT) instead of removing it, and a read inside the window
	 * serves the value aged
	 */
	public const string KEY_INVALIDATED = 'invalidated';
	
	/**
	 * The stamps the tag-index stores leave for the window: one per
	 * invalidated tag, and the type key itself for a clear
	 */
	public const string TYPE_INVALIDATED = 'invalidated';
	
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
			return $this->judge($id, $client->hMGet($id, $this->fetchedFields()));
		}
		// RedisClusterException does not extend RedisException, catch both
		catch(RedisException|RedisClusterException $exception)
		{
			$this->log($exception);
		}
		
		return null;
	}
	
	/**
	 * The fields fetch() reads with its HMGET
	 *
	 * @return list<string>
	 */
	protected function fetchedFields(): array
	{
		return [
			static::KEY_DATA,
			static::KEY_EPOCH,
			static::KEY_INVALIDATED,
		];
	}
	
	/**
	 * fetch()'s verdict on what its HMGET read ($item: the reply) - also the
	 * second look's, made with the lock (see lockAndLook())
	 */
	protected function judge(
		string $id,
		mixed $item,
	): mixed
	{
		$value = is_array($item) ? ($item[static::KEY_DATA] ?? false) : false;
		
		if($value !== false)
		{
			$value = $this->compressor
				->decompress($value);
			$value = $this->serializer
				->unserialize($value);
			
			// softly invalidated, inside its window (the mark and the data
			// expire with it): the value is handed out invalidated - served
			// while it is refreshed, a miss to a read without stale: (only a
			// soft value is ever marked; anything else marked is a miss)
			if(($item[static::KEY_INVALIDATED] ?? false) !== false)
			{
				$value = $value instanceof Stale
					? $value->invalidated()
					: null;
			}
			
			if($value !== null)
			{
				return $this->found($id, $value, $item[static::KEY_EPOCH]);
			}
		}
		
		$this->rememberMiss($id, is_array($item) ? $item[static::KEY_EPOCH] : false);
		
		return null;
	}
	
	/**
	 * The lock, the second look and the miss's stamp in one round trip (see
	 * MemoLock::lockAndQueue()): SET NX, the HMGET fetch() makes and TIME,
	 * pipelined - the look judged as fetch() judges it, the server time the
	 * stamp of the miss it leaves (stampMiss() keeps a stamp taken). The
	 * tag-index stores run standalone
	 */
	#[Override]
	protected function lockAndLook(
		string $id,
	): ?Closure
	{
		$client = $this->getClient();
		if($client instanceof RedisClient === false)
		{
			return null;
		}
		
		return function(string $lockKey, string $lockValue, int $ttlMs) use ($client, $id): array
		{
			$replies = $client->pipeline()
				->set($lockKey, $lockValue, ['NX', 'PX' => $ttlMs])
				->hMGet($id, $this->fetchedFields())
				->time()
				->exec();
			if(($replies[0] ?? false) !== true)
			{
				return [false, null];
			}
			
			$look = $this->fresh($this->judge($id, $replies[1] ?? false));
			if($look === null
				&& isset($this->misses[$id]))
			{
				$this->misses[$id]['stamp'] = $this->timeOf($replies[2] ?? null);
			}
			
			return [true, $look];
		};
	}
	
	/**
	 * The physical wipe stamps "cleared" in the same step: a value computed
	 * before the wipe and written after it is refused (its miss came first)
	 */
	#[Override]
	protected function clearedStamp(): array
	{
		return $this->isGuarded()
			? [$this->stampKey(), $this->invalidationWindowMs]
			: [];
	}
	
	/**
	 * A miss stamps the server time: a tag stamped (or the store cleared)
	 * after it refuses the write that follows
	 */
	#[Override]
	protected function missStamp(): ?string
	{
		return $this->serverTime();
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
			return $this->timeOf($client->time());
		}
		catch(RedisException|RedisClusterException $exception)
		{
			$this->log($exception);
		}
		
		return null;
	}
	
	/**
	 * A TIME reply in microseconds; null when it is none
	 */
	protected function timeOf(
		mixed $time,
	): ?string
	{
		return is_array($time) && isset($time[0], $time[1])
			? $time[0] . str_pad((string)$time[1], 6, '0', STR_PAD_LEFT)
			: null;
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
		
		if($this->functions
			->call('cache_stamp', $keys, [
				$this->invalidationWindowMs,
			]) === false)
		{
			$this->logRefused('cache_stamp');
			
			return false;
		}
		
		return true;
	}
}
