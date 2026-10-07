<?php
declare(strict_types=1);

namespace Ovos\Test\Cache\Store;

use Ovos\Cache\Stale;
use Ovos\Cache\Store\KeyValue;
use Ovos\Cache\Store\KeyValue\Redis as KeyValueRedis;
use Ovos\Cache\Store\KeyValue\Tags;
use Closure;
use RuntimeException;

use function count;
use function microtime;

/**
 * TraitStaleWhileRevalidate
 *
 * The rules of get(stale:) every store shares: a value past its ttl is
 * served at once while ONE process refreshes it - after the response, or
 * inline without one - only ever on ageing, never after an invalidation,
 * and the refresh's write guarded like a miss's
 *
 * @author Marcin Gil <mg@ovos.at>
 */
trait TraitStaleWhileRevalidate
{
	protected const string STALE_TAG = 'stale-tag';
	
	/**
	 * A fresh instance of the store under test - another process
	 */
	abstract protected function staleStore(): KeyValue;
	
	/**
	 * RULE: a value written with a stale time is a hit while it is fresh -
	 * computed once, read back as the value itself
	 */
	public function aFreshValueIsAHit(): bool
	{
		$store = $this->staleStore();
		$key = $this->staleKey('fresh');
		$calls = 0;
		$resolver = function() use (&$calls): string
		{
			$calls++;
			
			return 'value';
		};
		
		$first = $store->get($key, $resolver, 60, stale: 60);
		$second = $this->staleStore()->get($key, $resolver, 60, stale: 60);
		$store->delete($key);
		
		return $first === 'value'
			&& $second === 'value'
			&& $calls === 1;
	}
	
	/**
	 * RULE: a value past its ttl is served at once, and its refresh is handed
	 * to the deferrer (after the response) - nothing is computed before; run,
	 * it stores the fresh value
	 */
	public function aValuePastItsTtlIsServedStaleAndRefreshedLater(): bool
	{
		$store = $this->staleStore();
		$key = $this->staleKey('deferred');
		$this->capture($store, $deferred);
		$calls = 0;
		$this->writeAged($store, $key, 'old');
		
		$served = $store->get($key, $this->counting($calls, 'new'), 60, stale: 60);
		$before = $calls;
		foreach($deferred as $refresh)
		{
			$refresh();
		}
		$after = $this->staleStore()->get($key, queue: false);
		$store->delete($key);
		
		return $served === 'old'
			&& $before === 0
			&& count($deferred) === 1
			&& $calls === 1
			&& $after === 'new';
	}
	
	/**
	 * RULE: without a deferrer (a CLI, a worker) the refresh runs inline and
	 * the caller gets what it computed
	 */
	public function withoutADeferrerTheRefreshRunsInline(): bool
	{
		$store = $this->staleStore();
		$key = $this->staleKey('inline');
		$calls = 0;
		$this->writeAged($store, $key, 'old');
		
		$served = $store->get($key, $this->counting($calls, 'new'), 60, stale: 60);
		$after = $this->staleStore()->get($key, queue: false);
		$store->delete($key);
		
		return $served === 'new'
			&& $calls === 1
			&& $after === 'new';
	}
	
	/**
	 * RULE: a read that does not ask for stale values takes a value past its
	 * ttl for a miss
	 */
	public function aReadWithoutStaleTakesAnAgedValueForAMiss(): bool
	{
		$store = $this->staleStore();
		$key = $this->staleKey('strict');
		$calls = 0;
		$this->writeAged($store, $key, 'old');
		
		$plain = $this->staleStore()->get($key, queue: false);
		$computed = $store->get($key, $this->counting($calls, 'new'), 60);
		$store->delete($key);
		
		return $plain === null
			&& $computed === 'new'
			&& $calls === 1;
	}
	
	/**
	 * RULE: one process refreshes - a refresh that finds the lock taken
	 * leaves the item to its holder, serving the stale value
	 */
	public function aRefreshFindingTheLockTakenComputesNothing(): bool
	{
		$store = $this->staleStore();
		$holder = $this->staleStore();
		$key = $this->staleKey('elected');
		$id = $this->staleId($store, $key);
		$calls = 0;
		$this->writeAged($store, $key, 'old');
		
		$held = $holder->getMemoLock()->tryLock($id);
		$served = $store->get($key, $this->counting($calls, 'new'), 60, stale: 60);
		$holder->getMemoLock()->releaseActiveLock($id);
		$store->delete($key);
		
		return $held === true
			&& $served === 'old'
			&& $calls === 0;
	}
	
	/**
	 * RULE: a refresh looks once more after it took the lock - another
	 * process refreshed the item meanwhile, so it computes nothing
	 */
	public function aRefreshThatLandedMeanwhileIsNotRepeated(): bool
	{
		$first = $this->staleStore();
		$second = $this->staleStore();
		$key = $this->staleKey('repeated');
		$this->capture($first, $firstDeferred);
		$this->capture($second, $secondDeferred);
		$calls = 0;
		$this->writeAged($first, $key, 'old');
		
		$first->get($key, $this->counting($calls, 'new'), 60, stale: 60);
		$second->get($key, $this->counting($calls, 'newer'), 60, stale: 60);
		foreach([...$firstDeferred, ...$secondDeferred] as $refresh)
		{
			$refresh();
		}
		$after = $this->staleStore()->get($key, queue: false);
		$first->delete($key);
		
		return $calls === 1
			&& $after === 'new';
	}
	
	/**
	 * RULE: the refresh's write is guarded like a miss's - a delete landing
	 * while it computes refuses it, and the stale value never returns
	 */
	public function aDeleteDuringTheRefreshRefusesItsWrite(): bool
	{
		$store = $this->staleStore();
		$other = $this->staleStore();
		$key = $this->staleKey('raced');
		$this->capture($store, $deferred);
		$this->writeAged($store, $key, 'old');
		
		$store->get($key, function() use ($other, $key): string
		{
			// the source changed and another process invalidated the key
			// after this refresh read it
			$other->delete($key);
			
			return 'computed before the delete';
		}, 60, stale: 60);
		foreach($deferred as $refresh)
		{
			$refresh();
		}
		$after = $this->staleStore()->get($key, queue: false);
		$store->delete($key);
		
		return $after === null;
	}
	
	/**
	 * RULE: only ageing serves a stale value - an invalidated one is a miss,
	 * computed through the lock, never served (a tag invalidation on the
	 * tagging stores, a delete on the others)
	 */
	public function anInvalidatedValueIsNeverServedStale(): bool
	{
		$store = $this->staleStore();
		$key = $this->staleKey('invalidated');
		$this->capture($store, $deferred);
		$calls = 0;
		$this->writeAged($store, $key, 'old', [self::STALE_TAG]);
		
		$other = $this->staleStore();
		if($other instanceof Tags)
		{
			$other->invalidateTags([self::STALE_TAG]);
		}
		else
		{
			$other->delete($key);
		}
		
		$served = $store->get($key, $this->counting($calls, 'new'), 60, stale: 60);
		$store->delete($key);
		
		return $served === 'new'
			&& $calls === 1
			&& $deferred === [];
	}
	
	/**
	 * RULE: a refresh that throws keeps the stale value, and its lock is
	 * released - the next read past the ttl tries again
	 */
	public function aThrowingRefreshKeepsTheStaleValue(): bool
	{
		$store = $this->staleStore();
		$key = $this->staleKey('throwing');
		$id = $this->staleId($store, $key);
		$this->writeAged($store, $key, 'old');
		
		$served = $store->get($key, function(): never
		{
			throw new RuntimeException('the source is down');
		}, 60, stale: 60);
		$free = $this->staleStore()->getMemoLock()->tryLock($id);
		$this->staleStore()->getMemoLock()->releaseActiveLock($id);
		$store->delete($key);
		
		return $served === 'old'
			&& $free === true;
	}
	
	/**
	 * RULE: the lock's second look sees what get() sees - a value written
	 * with a stale time comes back unwrapped while it is fresh, and is
	 * nothing once it is not, so lockAndQueue() holds the lock for the caller
	 * to compute
	 */
	public function theLocksSecondLookUnwrapsAStaleValue(): bool
	{
		$store = $this->staleStore();
		$key = $this->staleKey('unwrapped');
		
		$store->set($key, new Stale('value', microtime(true) + 60), 60);
		$fresh = $store->lockAndQueue($key);
		$store->releaseActiveLock($key);
		
		$this->writeAged($store, $key, 'old');
		$aged = $store->lockAndQueue($key);
		$held = $store->releaseActiveLock($key);
		$store->delete($key);
		
		return $fresh === 'value'
			&& $aged === null
			&& $held === true;
	}
	
	/**
	 * RULE: an item written with a stale time lives ttl + stale (the Redis
	 * stores: the key's own TTL)
	 */
	public function aStaleValueLivesItsTtlPlusItsStaleTime(): bool
	{
		$store = $this->staleStore();
		if($store instanceof KeyValueRedis === false)
		{
			return true;
		}
		
		$key = $this->staleKey('hard-ttl');
		$store->get($key, fn() => 'value', 60, stale: 30);
		$ttl = (int)$store->getClient()->pTtl($this->staleId($store, $key));
		$store->delete($key);
		
		return $ttl > 60000
			&& $ttl <= 90000;
	}
	
	/**
	 * Writes $value as a value past its ttl, inside its stale time
	 */
	protected function writeAged(
		KeyValue $store,
		string $key,
		mixed $value,
		array $tags = [],
	): void
	{
		$aged = new Stale($value, microtime(true) - 1);
		
		if($store instanceof Tags)
		{
			$store->set($key, $aged, 60, $tags);
			
			return;
		}
		
		$store->set($key, $aged, 60);
	}
	
	/**
	 * Hands the store a deferrer that keeps what it is given in $deferred
	 */
	protected function capture(
		KeyValue $store,
		?array &$deferred,
	): void
	{
		$deferred = [];
		$store->setDeferrer(function(Closure $refresh) use (&$deferred): void
		{
			$deferred[] = $refresh;
		});
	}
	
	/**
	 * A resolver returning $value, counting its calls
	 */
	protected function counting(
		int &$calls,
		mixed $value,
	): Closure
	{
		return function() use (&$calls, $value): mixed
		{
			$calls++;
			
			return $value;
		};
	}
	
	protected function staleKey(
		string $name,
	): string
	{
		return 'stale-' . $name;
	}
	
	/**
	 * The store's prefixed id of $key (MemoLock locks by it)
	 */
	protected function staleId(
		KeyValue $store,
		string $key,
	): string
	{
		return $store instanceof KeyValueRedis
			? $store->prefix($key, $store->getType())
			: $store->prefix($key, $store->getGroup());
	}
}
