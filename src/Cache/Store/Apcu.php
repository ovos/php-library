<?php
declare(strict_types=1);

namespace Ovos\Cache\Store;

use Ovos\Cache\MemoLock\Apcu as MemoLock;
use Ovos\Cache\Stale;
use APCUIterator;
use Closure;
use Override;

use function apcu_cache_info;
use function apcu_clear_cache;
use function apcu_delete;
use function apcu_fetch;
use function apcu_store;
use function bin2hex;
use function ceil;
use function is_string;
use function max;
use function random_bytes;

/**
 * Apcu
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Apcu extends KeyValue
{
	/**
	 * The invalidation guard's mark beside an item: a fresh random token from
	 * every delete() and every write-through, for the window (see
	 * KeyValue::rememberMiss()) - a token, not a counter: a counter expired
	 * and counted again would repeat the value a miss saw
	 */
	public const string EPOCH_SUFFIX = '#epoch';
	
	public function getMemoLock(): MemoLock
	{
		if($this->memoLock === null)
		{
			$this->memoLock = new MemoLock(
				$this->prefixer->getPrefix(),
				$this->config,
				$this,
			);
		}
		
		return $this->memoLock;
	}
	
	public function set(
		string $key,
		mixed $value,
		int $ttl = 0,
	): bool
	{
		$id = $this->prefixer
			->prefix($key, $this->getGroup());
		// the miss this write follows, if any: refused when a delete (or a
		// write-through) marked the key since (see KeyValue::rememberMiss())
		$miss = $this->takeMiss($id);
		$value = $this->serializer
			->serialize($value);
		$value = $this->compressor
			->compress($value);
		
		try
		{
			if($miss === null)
			{
				// a write-through marks the key: a recomputation whose miss came
				// before it is refused
				$this->mark($id);
				
				return $this->storeValue($id, $value, $ttl);
			}
			
			if($this->epoch($id) !== $miss['epoch'])
			{
				return false;
			}
			
			$stored = $this->storeValue($id, $value, $ttl);
			
			// APCu has no atomic compare-and-store for this: a delete between
			// the check and the store takes the value back here - nothing
			// stale stays, a reader can see it for that instant
			if($stored && $this->epoch($id) !== $miss['epoch'])
			{
				apcu_delete($id);
				
				return false;
			}
			
			return $stored;
		}
		finally
		{
			$this->getMemoLock()
				->releaseActiveLock($id);
		}
	}
	
	protected function fetch(
		string $id,
	): mixed
	{
		$value = apcu_fetch($id);
		
		if($value !== false)
		{
			$value = $this->compressor
				->decompress($value);
			
			return $this->found($id,
				$this->serializer->unserialize($value),
				fn() => $this->epoch($id),
			);
		}
		
		$this->rememberMiss($id, $this->epoch($id));
		
		return null;
	}
	
	/**
	 * The item's mark: how many deletes the window remembers ('' = none)
	 */
	protected function epoch(
		string $id,
	): string
	{
		$epoch = apcu_fetch($id . static::EPOCH_SUFFIX);
		
		return $epoch === false ? '' : (string)$epoch;
	}
	
	/**
	 * A fresh token beside the item, for the window (nothing when the guard
	 * is off)
	 */
	protected function mark(
		string $id,
	): void
	{
		if($this->isGuarded())
		{
			apcu_store($id . static::EPOCH_SUFFIX, bin2hex(random_bytes(8)), max(1, (int)ceil($this->invalidationWindowMs / 1000)));
		}
	}
	
	/**
	 * The store itself - a step of its own, so a test can land a delete
	 * between the guard's check and it
	 */
	protected function storeValue(
		string $id,
		mixed $value,
		int $ttl,
	): bool
	{
		return apcu_store($id, $value, $ttl);
	}
	
	#[Override]
	public function itemId(
		string $key,
	): string
	{
		return $this->prefixer
			->prefix($key, $this->getGroup());
	}
	
	public function get(
		string $key,
		?Closure $resolver = null,
		int $ttl = 0,
		?bool $queue = null, // override of the config switch
		?int $queueLockTtlS = null, // override of the config value
		int $stale = 0, // seconds past the ttl a value is served while it is refreshed
		bool $soft = false, // APCu has no tags: no invalidation to soften, accepted for the API's sake
		bool $refresh = false, // recompute whatever is cached - guarded like a miss
		int $staleIfError = 0, // seconds past the stale time a value is kept, served when the computation fails
	): mixed
	{
		$id = $this->prefixer
			->prefix($key, $this->getGroup());
		$compute = fn() => $this->setFromResolver($key, $resolver, $ttl, $stale, $soft, $staleIfError);
		
		// a forced refresh: no hit, and no second look at the lock - a refresh
		// in flight is waited for, then this one computes too (lock-only)
		if($refresh)
		{
			$this->forceMiss($id);
			
			return $this->getMemoLock()
				->lockAndQueue($id, null, $compute, $queue, $queueLockTtlS);
		}
		
		// initial hit check (fast path); past its ttl, a value written with a
		// stale time is served while it is refreshed (see revalidate())
		$revalidate = $stale > 0 && $resolver !== null
			? $compute
			: null;
		$data = $this->fetch($id);
		if(($served = $this->served($id, $data, $revalidate)) !== null)
		{
			return $served;
		}
		
		$fallback = $this->errorFallback($data, $staleIfError);
		
		return $this->getMemoLock()
			->lockAndQueue(
				$id,
				fn() => $this->fresh($this->fetch($id)),
				fn() => $this->computeOrFallback($id, $compute, $fallback),
				$queue,
				$queueLockTtlS,
			);
	}
	
	/**
	 * The resolver's value, stored - with a stale time (get(stale:)) as the
	 * value and its fresh time, kept that long past it
	 */
	#[Override]
	public function setFromResolver(
		string $key,
		?Closure $resolver,
		int $ttl = 0,
		int $stale = 0,
		bool $soft = false,
		int $staleIfError = 0,
	): mixed
	{
		if($resolver === null)
		{
			return null;
		}
		
		// the resolver may change $ttl and $save by reference (no tags here -
		// see KeyValue::setFromResolver())
		$tags = [];
		$save = true;
		$value = $resolver($this, $key, $ttl, $tags, $save);
		
		if($save === false)
		{
			// nothing written: the lock goes now, not at its TTL
			$this->releaseActiveLock($key);
		}
		else if($value !== null)
		{
			[$stored, $storedTtl] = Stale::wrap($value, $ttl, $stale, $soft, $staleIfError);
			$this->set($key, $stored, $storedTtl);
		}
		
		return $value;
	}
	
	/**
	 * Manual lock control: returns the cached value when another process
	 * produced it meanwhile (this one holds NO lock then), otherwise what the
	 * resolver returns - null without one - while this process holds the
	 * lock: compute, then set() (or releaseActiveLock()). See
	 * KeyValue\Redis::lockAndQueue()
	 */
	public function lockAndQueue(
		string $key,
		?Closure $resolver = null,
		?int $queueLockTtlS = null, // override of the config value
	): mixed
	{
		$id = $this->prefixer
			->prefix($key, $this->getGroup());
		
		return $this->getMemoLock()
			->lockAndQueue(
				$id,
				fn() => $this->fresh($this->fetch($id)),
				$resolver,
				true,
				$queueLockTtlS,
			);
	}
	
	public function releaseActiveLock(
		string $key,
	): bool
	{
		$id = $this->prefixer
			->prefix($key, $this->getGroup());
		
		return $this->getMemoLock()
			->releaseActiveLock($id);
	}
	
	public function renewLock(
		string $key,
	): bool
	{
		$id = $this->prefixer
			->prefix($key, $this->getGroup());
		
		return $this->getMemoLock()
			->renewLock($id);
	}
	
	public function delete(
		string|APCUIterator $key,
	): bool
	{
		if(is_string($key))
		{
			$key = $this->prefixer
				->prefix($key, $this->getGroup());
			// this instance's own delete is no race (see KeyValue\Redis::tombstone())
			unset($this->misses[$key]);
			// the mark first: a write whose miss came before refuses, or takes
			// its value back (see set())
			$this->mark($key);
		}
		
		return apcu_delete($key);
	}
	
	public function info(
		bool $limited = false,
	): bool|array
	{
		return apcu_cache_info($limited);
	}
	
	public function clear(): bool // always true
	{
		return apcu_clear_cache();
	}
}
