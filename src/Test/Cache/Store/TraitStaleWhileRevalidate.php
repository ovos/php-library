<?php
declare(strict_types=1);

namespace Ovos\Test\Cache\Store;

use Ovos\Cache\Stale;
use Ovos\Cache\Store\KeyValue;
use Ovos\Cache\Store\KeyValue\Redis as KeyValueRedis;
use Ovos\Cache\Store\KeyValue\Tags;
use Ovos\Cache\Store\RedisVersioned;
use Ovos\Test\Exception\SkipException;
use Closure;
use RuntimeException;

use function count;
use function is_array;
use function microtime;
use function usleep;

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
	
	protected const string SOFT_TAG = 'soft-tag';
	
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
	 * RULE: the refresh holds its lock for the call's queueLockTtlMs - a
	 * refresh slower than the default lock TTL is not started a second time
	 */
	public function aRefreshHoldsItsLockForTheCallsLockTtl(): bool
	{
		$store = $this->staleStore();
		$rival = $this->staleStore();
		$key = $this->staleKey('refresh-lock-ttl');
		$id = $this->staleId($store, $key);
		$taken = null;
		$this->writeAged($store, $key, 'old');
		
		$served = $store->get($key, function() use ($rival, $id, &$taken): string
		{
			// past the default lock TTL (2 s on Redis, 1 s on APCu)
			usleep(2200000);
			$taken = $rival->getMemoLock()->tryLock($id);
			
			return 'new';
		}, 60, stale: 60, queueLockTtlMs: 6000);
		if($taken === true)
		{
			$rival->getMemoLock()->releaseActiveLock($id);
		}
		$store->delete($key);
		
		return $served === 'new'
			&& $taken === false;
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
	 * RULE: soft invalidation - a soft value a tag invalidation reached is
	 * served at once, and ONE refresh is handed to the deferrer; run, it
	 * stores the new value, which a strict read takes as fresh
	 */
	public function aSoftValueIsServedAfterAnInvalidationWhileOneRefreshRuns(): bool
	{
		$store = $this->softStore();
		$key = $this->staleKey('soft-deferred');
		$this->capture($store, $deferred);
		$calls = 0;
		$this->writeSoft($key, 'old');
		$this->staleStore()->invalidateTags([self::SOFT_TAG]);
		
		$served = $store->get($key, $this->counting($calls, 'new'), 60, [self::SOFT_TAG], stale: 60, soft: true);
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
	 * RULE: without a deferrer the refresh after a soft invalidation runs
	 * inline, and the caller gets the new value
	 */
	public function withoutADeferrerASoftInvalidationRefreshesInline(): bool
	{
		$store = $this->softStore();
		$key = $this->staleKey('soft-inline');
		$calls = 0;
		$this->writeSoft($key, 'old');
		$this->staleStore()->invalidateTags([self::SOFT_TAG]);
		
		$served = $store->get($key, $this->counting($calls, 'new'), 60, [self::SOFT_TAG], stale: 60, soft: true);
		$after = $this->staleStore()->get($key, queue: false);
		$store->delete($key);
		
		return $served === 'new'
			&& $calls === 1
			&& $after === 'new';
	}
	
	/**
	 * RULE: past its stale time after the invalidation a soft value is a
	 * miss - computed, not served - with a ttl or without one (its data then
	 * has no expiry of its own until the invalidation sets one)
	 */
	public function aSoftValuePastItsWindowIsAMiss(): bool
	{
		$store = $this->softStore();
		$key = $this->staleKey('soft-window');
		$forever = $this->staleKey('soft-window-forever');
		$this->capture($store, $deferred);
		$calls = 0;
		$this->writeSoft($key, 'old', stale: 1);
		$this->writeSoft($forever, 'old', ttl: 0, stale: 1);
		$this->staleStore()->invalidateTags([self::SOFT_TAG]);
		usleep(1300000);
		
		$served = $store->get($key, $this->counting($calls, 'new'), 60, [self::SOFT_TAG], stale: 1, soft: true);
		$servedForever = $store->get($forever, $this->counting($calls, 'new'), 0, [self::SOFT_TAG], stale: 1, soft: true);
		$store->delete($key);
		$store->delete($forever);
		
		return $served === 'new'
			&& $servedForever === 'new'
			&& $calls === 2
			&& $deferred === [];
	}
	
	/**
	 * RULE: a soft value written without a ttl is fresh until an invalidation
	 * reaches it - only then served stale while it is refreshed
	 */
	public function aSoftValueWithoutATtlAgesByAnInvalidationOnly(): bool
	{
		$store = $this->softStore();
		$key = $this->staleKey('soft-forever');
		$this->capture($store, $deferred);
		$calls = 0;
		$this->writeSoft($key, 'v1', ttl: 0);
		
		$fresh = $store->get($key, $this->counting($calls, 'unused'), 0, [self::SOFT_TAG], stale: 60, soft: true);
		$this->staleStore()->invalidateTags([self::SOFT_TAG]);
		$served = $store->get($key, $this->counting($calls, 'v2'), 0, [self::SOFT_TAG], stale: 60, soft: true);
		foreach($deferred as $refresh)
		{
			$refresh();
		}
		$after = $this->staleStore()->get($key, queue: false);
		$store->delete($key);
		
		return $fresh === 'v1'
			&& $served === 'v1'
			&& count($deferred) === 1
			&& $calls === 1
			&& $after === 'v2';
	}
	
	/**
	 * RULE: on the tag-index stores a soft value kept without a ttl stays
	 * without one after its refresh - the soft mark gave the key and the data
	 * the window's expiry, and the write over the marked item must not keep it
	 * (it did: the old data made the write an overwrite, and an overwrite
	 * without a ttl keeps the expiry it finds)
	 */
	public function aRefreshedSoftValueWithoutATtlKeepsNoExpiry(): bool
	{
		$store = $this->softStore();
		if($store instanceof RedisVersioned
			|| $store instanceof KeyValueRedis === false)
		{
			throw new SkipException('the soft mark is the tag-index stores\'');
		}
		
		$key = $this->staleKey('soft-forever-kept');
		$id = $this->staleId($store, $key);
		$client = $store->getClient();
		$this->capture($store, $deferred);
		$this->writeSoft($key, 'v1', ttl: 0);
		$this->staleStore()->invalidateTags([self::SOFT_TAG]);
		
		$store->get($key, fn() => 'v2', 0, [self::SOFT_TAG], stale: 60, soft: true);
		foreach($deferred as $refresh)
		{
			$refresh();
		}
		$keyTtl = (int)$client->pTtl($id);
		$dataTtl = $client->rawCommand('HPTTL', $id, 'FIELDS', 1, KeyValueRedis::KEY_DATA);
		$read = $this->staleStore()->get($key, queue: false);
		$store->delete($key);
		
		return count($deferred) === 1
			&& $read === 'v2'
			&& $keyTtl === -1
			&& is_array($dataTtl)
			&& (int)$dataTtl[0] === -1;
	}
	
	/**
	 * RULE: delete() stays hard for a soft value - the next read computes
	 */
	public function aDeleteStaysHardForASoftValue(): bool
	{
		$store = $this->softStore();
		$key = $this->staleKey('soft-delete');
		$this->capture($store, $deferred);
		$calls = 0;
		$this->writeSoft($key, 'old');
		$this->staleStore()->delete($key);
		
		$served = $store->get($key, $this->counting($calls, 'new'), 60, [self::SOFT_TAG], stale: 60, soft: true);
		$store->delete($key);
		
		return $served === 'new'
			&& $calls === 1
			&& $deferred === [];
	}
	
	/**
	 * RULE: clear() stays hard for a soft value - the next read computes
	 */
	public function aClearStaysHardForASoftValue(): bool
	{
		$store = $this->softStore();
		$key = $this->staleKey('soft-clear');
		$this->capture($store, $deferred);
		$calls = 0;
		$this->writeSoft($key, 'old');
		$this->staleStore()->clear();
		
		$served = $store->get($key, $this->counting($calls, 'new'), 60, [self::SOFT_TAG], stale: 60, soft: true);
		$store->delete($key);
		
		return $served === 'new'
			&& $calls === 1
			&& $deferred === [];
	}
	
	/**
	 * RULE: a recomputation that started before a soft invalidation never
	 * reads as fresh - the tag stores refuse its write (the soft mark renewed
	 * the epoch), the versioned stores let it land under the watermark it
	 * saw, older than the rule; either way a strict read misses, and what
	 * stays may only be served stale, as the old value would be
	 */
	public function aRecomputationAcrossASoftInvalidationIsRefused(): bool
	{
		$store = $this->softStore();
		$other = $this->staleStore();
		$key = $this->staleKey('soft-raced');
		$this->capture($store, $deferred);
		$this->writeAged($store, $key, 'old', [self::SOFT_TAG], soft: true);
		
		$store->get($key, function() use ($other): string
		{
			// the source changed and another process invalidated the tag
			// (softly) after this refresh read it
			$other->invalidateTags([self::SOFT_TAG]);
			
			return 'computed before the edit';
		}, 60, [self::SOFT_TAG], stale: 60, soft: true);
		foreach($deferred as $refresh)
		{
			$refresh();
		}
		$strict = $this->staleStore()->get($key, queue: false);
		$stored = $this->staleStore()->peek($key);
		$store->delete($key);
		
		return count($deferred) === 1
			&& $strict === null
			&& ($stored === null || ($stored instanceof Stale && $stored->isFresh() === false));
	}
	
	/**
	 * RULE: a refresh that started from a softly invalidated read is guarded
	 * too - a second invalidation landing while it computes leaves its value
	 * reading as anything but fresh (the read was remembered as a miss)
	 */
	public function aRefreshAcrossASecondInvalidationIsRefused(): bool
	{
		$store = $this->softStore();
		$other = $this->staleStore();
		$key = $this->staleKey('soft-second');
		$this->capture($store, $deferred);
		$this->writeSoft($key, 'old');
		$other->invalidateTags([self::SOFT_TAG]);
		
		$store->get($key, function() use ($other): string
		{
			// another edit lands while the refresh computes
			$other->invalidateTags([self::SOFT_TAG]);
			
			return 'computed before the second edit';
		}, 60, [self::SOFT_TAG], stale: 60, soft: true);
		foreach($deferred as $refresh)
		{
			$refresh();
		}
		$strict = $this->staleStore()->get($key, queue: false);
		$store->delete($key);
		
		return count($deferred) === 1
			&& $strict === null;
	}
	
	/**
	 * RULE: on the tag-index stores the soft mark renews the epoch - a
	 * recomputation whose miss carries no stamp, guarded by the epoch alone,
	 * is refused as a tombstone would refuse it. peek() is that read (the
	 * page cache's): it remembers the miss without a stamp - get() stamps it
	 * right before the computation, so the tag's stamp would refuse it too
	 */
	public function anUnstampedRecomputationAcrossASoftInvalidationIsRefused(): bool
	{
		$store = $this->softStore();
		if($store instanceof RedisVersioned
			|| $store instanceof KeyValueRedis === false)
		{
			throw new SkipException('the soft mark is the tag-index stores\'');
		}
		
		$key = $this->staleKey('soft-unstamped');
		$this->writeAged($store, $key, 'old', [self::SOFT_TAG], soft: true);
		
		// peek(), a computation, set() - the aged read is an unstamped miss
		$peeked = $store->peek($key);
		$this->staleStore()->invalidateTags([self::SOFT_TAG]);
		$store->set($key, 'computed before the edit', 60, [self::SOFT_TAG]);
		$strict = $this->staleStore()->get($key, queue: false);
		$store->delete($key);
		
		return $peeked instanceof Stale
			&& $peeked->isFresh() === false
			&& $strict === null;
	}
	
	/**
	 * RULE: a read without stale: takes a softly invalidated value for a miss
	 */
	public function aStrictReadMissesASoftlyInvalidatedValue(): bool
	{
		$store = $this->softStore();
		$key = $this->staleKey('soft-strict');
		$calls = 0;
		$this->writeSoft($key, 'old');
		$this->staleStore()->invalidateTags([self::SOFT_TAG]);
		
		$plain = $this->staleStore()->get($key, queue: false);
		$computed = $store->get($key, $this->counting($calls, 'new'), 60, [self::SOFT_TAG]);
		$store->delete($key);
		
		return $plain === null
			&& $computed === 'new'
			&& $calls === 1;
	}
	
	/**
	 * RULE: on the tag-index stores a tag invalidation MARKS a soft value's
	 * item (the data kept, expiring with the window) instead of tombstoning
	 * it, and the next write ends the mark - its fields go
	 */
	public function aWriteEndsASoftInvalidation(): bool
	{
		$store = $this->softStore();
		if($store instanceof RedisVersioned
			|| $store instanceof KeyValueRedis === false)
		{
			throw new SkipException('the soft mark is the tag-index stores\'');
		}
		
		$key = $this->staleKey('soft-mark');
		$id = $this->staleId($store, $key);
		$client = $store->getClient();
		$this->writeSoft($key, 'old');
		$this->staleStore()->invalidateTags([self::SOFT_TAG]);
		
		$marked = (int)$client->hExists($id, KeyValueRedis::KEY_INVALIDATED) === 1
			&& (int)$client->hExists($id, KeyValueRedis::KEY_DATA) === 1;
		$this->staleStore()->set($key, 'plain', 60, [self::SOFT_TAG]);
		$cleared = (int)$client->hExists($id, KeyValueRedis::KEY_INVALIDATED) === 0
			&& (int)$client->hExists($id, KeyValueRedis::KEY_SOFT) === 0;
		$read = $this->staleStore()->get($key, queue: false);
		$store->delete($key);
		
		return $marked
			&& $cleared
			&& $read === 'plain';
	}
	
	/**
	 * Writes $value as a soft value (get(stale:, soft: true)) from another
	 * process, tagged SOFT_TAG
	 */
	protected function writeSoft(
		string $key,
		mixed $value,
		int $ttl = 60,
		int $stale = 60,
	): void
	{
		$this->staleStore()->get($key, fn() => $value, $ttl, [self::SOFT_TAG], stale: $stale, soft: true);
	}
	
	/**
	 * The store under test when it has tags - soft invalidation softens a tag
	 * invalidation; APCu has none
	 */
	protected function softStore(): Tags
	{
		$store = $this->staleStore();
		if($store instanceof Tags === false)
		{
			throw new SkipException('no tags: soft invalidation has nothing to soften');
		}
		
		return $store;
	}
	
	/**
	 * Writes $value as a value past its ttl, inside its stale time
	 */
	protected function writeAged(
		KeyValue $store,
		string $key,
		mixed $value,
		array $tags = [],
		bool $soft = false,
	): void
	{
		$aged = new Stale($value, microtime(true) - 1, 60, $soft);
		
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
