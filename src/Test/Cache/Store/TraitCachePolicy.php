<?php
declare(strict_types=1);

namespace Ovos\Test\Cache\Store;

use Ovos\Cache\Computed;
use Ovos\Cache\Stale;
use Ovos\Cache\Store\KeyValue;
use Ovos\Cache\Store\KeyValue\Redis as KeyValueRedis;
use Ovos\Cache\Store\KeyValue\Tags;
use Ovos\Cache\Store\RedisVersioned;
use Ovos\Test\Exception\SkipException;
use ArgumentCountError;
use Closure;
use InvalidArgumentException;
use RuntimeException;

use function apcu_exists;
use function bin2hex;
use function count;
use function microtime;
use function random_bytes;
use function usleep;

/**
 * TraitCachePolicy
 *
 * What a resolver and an invalidation decide, the rules every store shares:
 * a resolver is called as function($store, $key) and may return a Computed
 * - its own TTL, its own tags, or nothing to store; get()'s options come as
 * named arguments, an unknown one throws; get(refresh: true) recomputes
 * whatever is cached;
 * get(staleIfError:) keeps a value past its stale time, served only when the
 * computation fails - a failure is remembered: the processes that waited for
 * it and the reads after it serve the kept value without computing, and one
 * process tries again per retry time; invalidateTags(hard: true) is a miss at
 * once, a soft value too. Used with TraitStaleWhileRevalidate (its helpers)
 *
 * @author Marcin Gil <mg@ovos.at>
 */
trait TraitCachePolicy
{
	protected const string POLICY_TAG = 'policy-tag';
	
	/**
	 * RULE: a Computed's TTL replaces the call's - the value expires when the
	 * resolver said, and the caller gets the value, never the Computed
	 */
	public function aComputedTtlReplacesTheCallsTtl(): bool
	{
		$store = $this->staleStore();
		$key = $this->staleKey('computed-ttl');
		$store->delete($key);
		
		$value = $store->get($key, fn() => new Computed('short-lived', ttl: 10), 60);
		$hit = $this->staleStore()->get($key, queue: false);
		// Check the backend's actual data TTL, including Redis field expiry
		// when the invalidation mark outlives the value.
		$ttl = $this->storedTtlMs($store, $key);
		$store->delete($key);
		
		return $value === 'short-lived'
			&& $hit === 'short-lived'
			&& $ttl > 9000 && $ttl <= 10000;
	}
	
	/**
	 * RULE: a Computed's tags replace the call's - a tag invalidation reaches
	 * the value by the resolver's tags, not by the call's
	 */
	public function aComputedsTagsReplaceTheCallsTags(): bool
	{
		$store = $this->policyTagStore();
		$key = $this->staleKey('computed-tags');
		$store->delete($key);
		
		$store->get($key, fn() => new Computed('tagged', tags: ['policy-resolver']), 60, [self::POLICY_TAG]);
		$this->staleStore()->invalidateTags([self::POLICY_TAG]);
		$byCallTag = $this->staleStore()->get($key, queue: false);
		$this->staleStore()->invalidateTags(['policy-resolver']);
		$byResolverTag = $this->staleStore()->get($key, queue: false);
		$store->delete($key);
		
		return $byCallTag === 'tagged'
			&& $byResolverTag === null;
	}
	
	/**
	 * RULE: a Computed with save: false hands the value back, stores nothing
	 * and frees the lock - the next caller computes at once, not after the
	 * lock's TTL
	 */
	public function aComputedWithSaveFalseStoresNothingAndFreesTheLock(): bool
	{
		$store = $this->staleStore();
		$key = $this->staleKey('computed-unsaved');
		$store->delete($key);
		
		$value = $store->get($key, fn() => new Computed('not stored', save: false), 60);
		$stored = $this->staleStore()->get($key, queue: false);
		$calls = 0;
		$started = microtime(true);
		$next = $this->staleStore()->get($key, $this->counting($calls, 'computed'), 60);
		$waited = microtime(true) - $started;
		$store->delete($key);
		
		return $value === 'not stored'
			&& $stored === null
			&& $next === 'computed' && $calls === 1
			&& $waited < 1.0;
	}
	
	/**
	 * RULE: a resolver that returns null stores nothing and frees the lock at
	 * once - the next caller computes without waiting for the lock's TTL
	 */
	public function aNullResultStoresNothingAndFreesTheLock(): bool
	{
		$store = $this->staleStore();
		$key = $this->staleKey('resolver-null');
		$store->delete($key);
		
		$value = $store->get($key, fn() => null, 60);
		$calls = 0;
		$started = microtime(true);
		$next = $this->staleStore()->get($key, $this->counting($calls, 'computed'), 60);
		$waited = microtime(true) - $started;
		$store->delete($key);
		
		return $value === null
			&& $next === 'computed' && $calls === 1
			&& $waited < 0.5;
	}
	
	/**
	 * RULE: set() with a null value removes the item, as delete() does - a
	 * reader misses, the value before it is not served, and nothing of it is
	 * left stored (null is what a miss reads as: stored, it read back as '',
	 * or the versioned stores threw)
	 */
	public function aNullValueRemovesTheItem(): bool
	{
		$store = $this->staleStore();
		$key = $this->staleKey('null-value');
		$id = $this->staleId($store, $key);
		$store->set($key, 'before', 60);
		
		$removed = $store->set($key, null, 60);
		$read = $this->staleStore()->get($key, queue: false);
		$peeked = $this->staleStore()->peek($key);
		$left = $store instanceof KeyValueRedis
			? $store->getClient()->hExists($id, KeyValueRedis::KEY_DATA)
			: apcu_exists($id);
		$store->delete($key);
		
		return $removed === true
			&& $read === null
			&& $peeked === null
			&& $left === false;
	}
	
	/**
	 * RULE: a resolver is called with the store and the key alone - one still
	 * declaring the references it was once given fails loudly, never silently
	 */
	public function aResolverDeclaringReferencesFailsLoudly(): bool
	{
		$store = $this->staleStore();
		$key = $this->staleKey('resolver-references');
		$store->delete($key);
		
		try
		{
			$store->get($key, function(KeyValue $store, string $key, int &$ttl): string
			{
				$ttl = 1;
				
				return 'never';
			}, 60);
			
			return false;
		}
		catch(ArgumentCountError)
		{
			return $this->staleStore()->get($key, queue: false) === null;
		}
		finally
		{
			$store->delete($key);
		}
	}
	
	/**
	 * RULE: get() takes its options by name - a misspelt one throws, it is
	 * never silently ignored
	 */
	public function anUnknownOptionThrowsThroughGet(): bool
	{
		try
		{
			$this->staleStore()->get($this->staleKey('unknown-option'), fn() => 'v', 60, [], stal: 60);
			
			return false;
		}
		catch(InvalidArgumentException)
		{
			return true;
		}
	}
	
	/**
	 * RULE: options passed by position after $tags are read in the documented
	 * order (queue, queueLockTtlMs, stale, ...) - the same on every store
	 */
	public function positionalOptionsAreReadInOrder(): bool
	{
		$store = $this->staleStore();
		$key = $this->staleKey('positional');
		$store->delete($key);
		
		$store->get($key, fn() => 'v', 60, [], null, null, 45);
		$stored = $this->staleStore()->peek($key);
		$store->delete($key);
		
		return $stored instanceof Stale
			&& $stored->staleFor === 45;
	}
	
	/**
	 * RULE: get(refresh: true) recomputes over a fresh value and stores the
	 * new one
	 */
	public function aForcedRefreshRecomputesOverAFreshValue(): bool
	{
		$store = $this->staleStore();
		$key = $this->staleKey('forced-refresh');
		$store->delete($key);
		
		$store->get($key, fn() => 'v1', 60);
		$calls = 0;
		$refreshed = $this->staleStore()->get($key, $this->counting($calls, 'v2'), 60, refresh: true);
		$read = $this->staleStore()->get($key, queue: false);
		$store->delete($key);
		
		return $refreshed === 'v2' && $calls === 1 && $read === 'v2';
	}
	
	/**
	 * RULE: a forced refresh is guarded like a miss - a delete while it
	 * computes refuses its write
	 */
	public function aForcedRefreshAcrossADeleteIsRefused(): bool
	{
		$store = $this->staleStore();
		$key = $this->staleKey('forced-refresh-guarded');
		$store->delete($key);
		
		$store->get($key, fn() => 'v1', 60);
		$refreshed = $this->staleStore()->get($key, function() use ($key): string
		{
			$this->staleStore()->delete($key);
			
			return 'computed before the delete';
		}, 60, refresh: true);
		$read = $this->staleStore()->get($key, queue: false);
		$store->delete($key);
		
		return $refreshed === 'computed before the delete' && $read === null;
	}
	
	/**
	 * RULE: past its stale time a value kept for errors is served when the
	 * computation fails - the caller gets the old value, not the exception
	 */
	public function aValuePastItsStaleTimeIsServedWhenTheComputationFails(): bool
	{
		$store = $this->staleStore();
		$key = $this->staleKey('stale-if-error');
		$this->writeErrorKept($store, $key, 'last good');
		
		$served = $this->staleStore()->get($key, function(): never
		{
			throw new RuntimeException('the origin is down');
		}, 60, stale: 1, staleIfError: 60);
		$store->delete($key);
		
		return $served === 'last good';
	}
	
	/**
	 * RULE: past its stale time a value kept for errors is a miss - computed
	 * at once, not served stale while a refresh runs
	 */
	public function aValuePastItsStaleTimeIsAMissWhenTheComputationSucceeds(): bool
	{
		$store = $this->staleStore();
		$key = $this->staleKey('stale-if-error-miss');
		$this->writeErrorKept($store, $key, 'last good');
		$this->capture($store, $deferred);
		
		$calls = 0;
		$served = $store->get($key, $this->counting($calls, 'new'), 60, stale: 1, staleIfError: 60);
		$read = $this->staleStore()->get($key, queue: false);
		$store->delete($key);
		$store->setDeferrer(null);
		
		return $served === 'new' && $calls === 1 && $deferred === [] && $read === 'new';
	}
	
	/**
	 * RULE: a value written with staleIfError is kept past its stale time -
	 * there for the failure that comes after it, a miss to a computation that
	 * succeeds (computed at once, not served stale); with a stale time and
	 * without one
	 */
	public function staleIfErrorKeepsTheValuePastItsStaleTime(): bool
	{
		$store = $this->staleStore();
		$kept = $this->staleKey('stale-if-error-kept');
		$missed = $this->staleKey('stale-if-error-missed');
		$noStale = $this->staleKey('stale-if-error-no-stale');
		$store->delete($kept);
		$store->delete($missed);
		$store->delete($noStale);
		$failing = function(): never
		{
			throw new RuntimeException('the origin is down');
		};
		
		$store->get($kept, fn() => 'kept', 1, stale: 1, staleIfError: 60);
		$store->get($missed, fn() => 'old', 1, stale: 1, staleIfError: 60);
		$store->get($noStale, fn() => 'kept without stale', 1, staleIfError: 60);
		// Verify retention before ageing the envelopes the real writer made.
		// Dropping staleIfError from the write must still fail this test.
		$keptTtl = $this->storedTtlMs($store, $kept);
		$missedTtl = $this->storedTtlMs($store, $missed);
		$noStaleTtl = $this->storedTtlMs($store, $noStale);
		$this->ageStoredValue($store, $kept, 10);
		$this->ageStoredValue($store, $missed, 10);
		$this->ageStoredValue($store, $noStale, 10);
		$served = $this->staleStore()->get($kept, $failing, 1, stale: 1, staleIfError: 60);
		$reader = $this->staleStore();
		$this->capture($reader, $deferred);
		$calls = 0;
		$computed = $reader->get($missed, $this->counting($calls, 'new'), 1, stale: 1, staleIfError: 60);
		$reader->setDeferrer(null);
		$servedWithoutStale = $this->staleStore()->get($noStale, $failing, 1, staleIfError: 60);
		$store->delete($kept);
		$store->delete($missed);
		$store->delete($noStale);
		
		return $served === 'kept'
			&& $keptTtl > 61000 && $keptTtl <= 62000
			&& $missedTtl > 61000 && $missedTtl <= 62000
			&& $noStaleTtl > 60000 && $noStaleTtl <= 61000
			&& $computed === 'new' && $calls === 1 && $deferred === []
			&& $servedWithoutStale === 'kept without stale';
	}
	
	/**
	 * RULE: a read that does not ask for staleIfError gets the computation's
	 * exception, a value kept for errors notwithstanding
	 */
	public function withoutStaleIfErrorAFailingComputationThrows(): bool
	{
		$store = $this->staleStore();
		$key = $this->staleKey('stale-if-error-not-asked');
		$this->writeErrorKept($store, $key, 'last good');
		
		try
		{
			$this->staleStore()->get($key, function(): never
			{
				throw new RuntimeException('the origin is down');
			}, 60, stale: 1);
			
			return false;
		}
		catch(RuntimeException)
		{
			return true;
		}
		finally
		{
			$store->delete($key);
		}
	}
	
	/**
	 * RULE: a value past the time kept for errors is no fallback while its
	 * item is still there - the computation's exception goes to the caller
	 */
	public function aValuePastTheTimeKeptForErrorsIsNoFallback(): bool
	{
		$store = $this->staleStore();
		$key = $this->staleKey('past-error-time');
		$store->delete($key);
		$expired = new Stale('old', microtime(true) - 100, 1, false, errorFor: 10);
		if($store instanceof Tags)
		{
			$store->set($key, $expired, 60, [self::POLICY_TAG]);
		}
		else
		{
			$store->set($key, $expired, 60);
		}
		
		try
		{
			$this->staleStore()->get($key, function(): never
			{
				throw new RuntimeException('the origin is down');
			}, 60, stale: 1, staleIfError: 10);
			
			return false;
		}
		catch(RuntimeException)
		{
			return true;
		}
		finally
		{
			$store->delete($key);
		}
	}
	
	/**
	 * RULE: a value past its stale time is a miss while its item is still
	 * there - the value's own times end its stale window, not the store's TTL
	 * (computed at once, nothing served stale, nothing deferred)
	 */
	public function aValuePastItsStaleTimeIsAMissWhileItIsStillThere(): bool
	{
		$store = $this->staleStore();
		$key = $this->staleKey('past-stale');
		$store->delete($key);
		$aged = new Stale('old', microtime(true) - 100, 1);
		if($store instanceof Tags)
		{
			$store->set($key, $aged, 60, [self::POLICY_TAG]);
		}
		else
		{
			$store->set($key, $aged, 60);
		}
		$this->capture($store, $deferred);
		
		$calls = 0;
		$served = $store->get($key, $this->counting($calls, 'new'), 60, stale: 60);
		$store->delete($key);
		$store->setDeferrer(null);
		
		return $served === 'new' && $calls === 1 && $deferred === [];
	}
	
	/**
	 * RULE: a soft value kept for errors is still served after a soft
	 * invalidation - its window is the invalidation's, not its stale time
	 */
	public function aSoftValueKeptForErrorsIsServedAfterAnInvalidation(): bool
	{
		$store = $this->policyTagStore();
		$key = $this->staleKey('soft-error-kept');
		$store->delete($key);
		$this->capture($store, $deferred);
		
		$store->get($key, fn() => 'v1', 60, [self::SOFT_TAG], stale: 60, soft: true, staleIfError: 60);
		$this->staleStore()->invalidateTags([self::SOFT_TAG]);
		$calls = 0;
		$served = $store->get($key, $this->counting($calls, 'v2'), 60, [self::SOFT_TAG], stale: 60, soft: true, staleIfError: 60);
		$store->delete($key);
		$store->setDeferrer(null);
		
		return $served === 'v1' && $calls === 0 && count($deferred) === 1;
	}
	
	/**
	 * RULE: a hard invalidation of a soft value is a miss at once - computed
	 * inline, nothing served stale
	 */
	public function aHardInvalidationOfASoftValueIsAMissAtOnce(): bool
	{
		$store = $this->policyTagStore();
		$key = $this->staleKey('hard-soft');
		$this->capture($store, $deferred);
		$this->writeSoft($key, 'v1', ttl: 0);
		
		$this->staleStore()->invalidateTags([self::SOFT_TAG], hard: true);
		$calls = 0;
		$served = $store->get($key, $this->counting($calls, 'v2'), 0, [self::SOFT_TAG], stale: 60, soft: true);
		$store->delete($key);
		$store->setDeferrer(null);
		
		return $served === 'v2' && $calls === 1 && $deferred === [];
	}
	
	/**
	 * RULE: a soft invalidation after a hard one does not soften it - the
	 * value written before the hard one is a miss
	 */
	public function aSoftInvalidationAfterAHardOneStaysHard(): bool
	{
		$store = $this->policyTagStore();
		$key = $this->staleKey('hard-then-soft');
		$this->capture($store, $deferred);
		$this->writeSoft($key, 'v1', ttl: 0);
		
		$this->staleStore()->invalidateTags([self::SOFT_TAG], hard: true);
		$this->staleStore()->invalidateTags([self::SOFT_TAG]);
		$calls = 0;
		$served = $store->get($key, $this->counting($calls, 'v2'), 0, [self::SOFT_TAG], stale: 60, soft: true);
		$store->delete($key);
		$store->setDeferrer(null);
		
		return $served === 'v2' && $calls === 1 && $deferred === [];
	}
	
	/**
	 * RULE (versioned stores): a hard invalidation after a soft one makes it
	 * hard - the rules remember the hard one. On the tag-index stores a softly
	 * invalidated value has left its tags, so it stays soft for its window
	 */
	public function aHardInvalidationAfterASoftOneMakesItHard(): bool
	{
		$store = $this->policyTagStore();
		if($store instanceof RedisVersioned === false)
		{
			throw new SkipException('a softly invalidated value has left its tags on this store');
		}
		
		$key = $this->staleKey('soft-then-hard');
		$this->capture($store, $deferred);
		$this->writeSoft($key, 'v1', ttl: 0);
		
		$this->staleStore()->invalidateTags([self::SOFT_TAG]);
		$this->staleStore()->invalidateTags([self::SOFT_TAG], hard: true);
		$calls = 0;
		$served = $store->get($key, $this->counting($calls, 'v2'), 0, [self::SOFT_TAG], stale: 60, soft: true);
		$store->delete($key);
		$store->setDeferrer(null);
		
		return $served === 'v2' && $calls === 1 && $deferred === [];
	}
	
	/**
	 * RULE: without hard: a soft value is still served after a tag
	 * invalidation (hard defaults to off)
	 */
	public function aTagInvalidationStaysSoftByDefault(): bool
	{
		$store = $this->policyTagStore();
		$key = $this->staleKey('soft-default');
		$this->capture($store, $deferred);
		$this->writeSoft($key, 'v1', ttl: 0);
		
		$this->staleStore()->invalidateTags([self::SOFT_TAG], hard: false);
		$calls = 0;
		$served = $store->get($key, $this->counting($calls, 'v2'), 0, [self::SOFT_TAG], stale: 60, soft: true);
		$store->delete($key);
		$store->setDeferrer(null);
		
		return $served === 'v1' && $calls === 0 && count($deferred) === 1;
	}
	
	/**
	 * RULE: a computation that failed is not repeated by the next read - the
	 * value kept for errors is served at once, nothing computed, nothing
	 * queued, until the retry time has passed
	 */
	public function aFailedComputationIsNotRepeatedByTheNextRead(): bool
	{
		$store = $this->staleStore();
		$key = $this->errorKey('remembered');
		$this->writeErrorKept($store, $key, 'last good');
		$calls = 0;
		
		$first = $this->staleStore()->get($key, $this->failing($calls), 60, stale: 1, staleIfError: 60);
		$callsOfFirst = $calls;
		$reader = $this->staleStore();
		$this->capture($reader, $deferred);
		$second = $reader->get($key, $this->failing($calls), 60, stale: 1, staleIfError: 60);
		$reader->setDeferrer(null);
		$store->delete($key);
		
		return $first === 'last good' && $callsOfFirst === 1
			&& $second === 'last good' && $calls === 1 && $deferred === [];
	}
	
	/**
	 * RULE: a process that waited for a computation which failed serves the
	 * value kept for errors - it does not take the lock and compute in turn.
	 * Another process holds the lock; while this one waits, that one fails and
	 * remembers it (here: at this process's second read of the value)
	 */
	public function aProcessThatWaitedForAFailedComputationServesTheKeptValue(): bool
	{
		$store = $this->staleStore();
		$key = $this->errorKey('waited');
		$store->delete($key);
		$kept = new Stale(new ReadSpy('last good'), microtime(true) - 10, 1, false, errorFor: 60);
		$store instanceof Tags
			? $store->set($key, $kept, 70, [self::POLICY_TAG])
			: $store->set($key, $kept, 70);
		$id = $store->itemId($key);
		// the other process: it holds the lock, and its lock goes when it has failed
		$holder = $this->staleStore()->getMemoLock();
		$held = $holder->tryLock($id, 300);
		ReadSpy::watch(function(int $reads) use ($holder, $id): void
		{
			// the first read is get()'s own; the second is the look after the wait
			if($reads === 2)
			{
				$holder->rememberFailure($id, 3000);
			}
		});
		
		try
		{
			$calls = 0;
			$served = $this->staleStore()->get($key, $this->failing($calls), 60, stale: 1, staleIfError: 60);
			
			return $held
				&& $served instanceof ReadSpy && $served->value === 'last good'
				&& $calls === 0;
		}
		finally
		{
			ReadSpy::watch(null);
			$holder->releaseActiveLock($id);
			$store->delete($key);
		}
	}
	
	/**
	 * RULE: once the retry time has passed, one process tries again - after
	 * the response where there is a deferrer, and the read is answered with
	 * the kept value at once; a failure of that attempt is remembered anew
	 */
	public function onceTheRetryTimeHasPassedOneProcessTriesAgain(): bool
	{
		$store = $this->staleStore();
		$key = $this->errorKey('retry');
		$this->writeErrorKept($store, $key, 'last good');
		$calls = 0;
		
		$this->retrying(50)->get($key, $this->failing($calls), 60, stale: 1, staleIfError: 60);
		usleep(60_000);
		$reader = $this->retrying(50);
		$this->capture($reader, $deferred);
		$served = $reader->get($key, $this->failing($calls), 60, stale: 1, staleIfError: 60);
		$callsBeforeTheRefresh = $calls;
		$queued = count($deferred);
		foreach($deferred as $refresh)
		{
			$refresh();
		}
		$callsAfterTheRefresh = $calls;
		// right after the attempt failed: not yet again
		$this->capture($reader, $deferred);
		$again = $reader->get($key, $this->failing($calls), 60, stale: 1, staleIfError: 60);
		$reader->setDeferrer(null);
		$store->delete($key);
		
		return $served === 'last good' && $callsBeforeTheRefresh === 1 && $queued === 1
			&& $callsAfterTheRefresh === 2
			&& $again === 'last good' && $calls === 2 && $deferred === [];
	}
	
	/**
	 * RULE: whoever does not win that attempt serves the kept value at once -
	 * another process holds the lock, this one computes nothing
	 */
	public function aReadBesideTheNextAttemptServesTheKeptValue(): bool
	{
		$store = $this->staleStore();
		$key = $this->errorKey('retry-lost');
		$this->writeErrorKept($store, $key, 'last good');
		$calls = 0;
		
		$this->retrying(50)->get($key, $this->failing($calls), 60, stale: 1, staleIfError: 60);
		usleep(60_000);
		$id = $store->itemId($key);
		$holder = $this->staleStore()->getMemoLock();
		$held = $holder->tryLock($id, 300);
		
		try
		{
			$served = $this->retrying(50)->get($key, $this->failing($calls), 60, stale: 1, staleIfError: 60);
			
			return $held && $served === 'last good' && $calls === 1;
		}
		finally
		{
			$holder->releaseActiveLock($id);
			$store->delete($key);
		}
	}
	
	/**
	 * RULE: an attempt that succeeds stores the new value - without a
	 * deferrer the read that made it is answered with it, and the next read
	 * is a hit
	 */
	public function anAttemptThatSucceedsStoresTheNewValue(): bool
	{
		$store = $this->staleStore();
		$key = $this->errorKey('retry-succeeds');
		$this->writeErrorKept($store, $key, 'last good');
		$failed = 0;
		$calls = 0;
		
		$this->retrying(50)->get($key, $this->failing($failed), 60, stale: 1, staleIfError: 60);
		usleep(60_000);
		$served = $this->retrying(50)->get($key, $this->counting($calls, 'new'), 60, stale: 1, staleIfError: 60);
		$read = $this->staleStore()->get($key, queue: false);
		$store->delete($key);
		
		return $failed === 1 && $served === 'new' && $calls === 1 && $read === 'new';
	}
	
	/**
	 * RULE: an attempt that succeeds forgets the failure - a value the
	 * resolver does not store (save: false) leaves the next read to compute, as
	 * with no failure, instead of the kept value until the next attempt
	 */
	public function anAttemptWhoseValueIsNotStoredForgetsTheFailure(): bool
	{
		$store = $this->staleStore();
		$key = $this->errorKey('retry-unsaved');
		$this->writeErrorKept($store, $key, 'last good');
		$failed = 0;
		$calls = 0;
		$unsaved = function() use (&$calls): Computed
		{
			$calls++;
			
			return new Computed('new', save: false);
		};
		
		$this->retrying(50)->get($key, $this->failing($failed), 60, stale: 1, staleIfError: 60);
		usleep(60_000);
		// the attempt: computed, not stored - then a read within the retry time
		$first = $this->retrying(50)->get($key, $unsaved, 60, stale: 1, staleIfError: 60);
		$second = $this->retrying(50)->get($key, $unsaved, 60, stale: 1, staleIfError: 60);
		$store->delete($key);
		
		return $failed === 1 && $first === 'new' && $second === 'new' && $calls === 2;
	}
	
	/**
	 * RULE: a failure counts for the value it was about - one remembered
	 * before the value's fresh time ended belongs to a value replaced since,
	 * and the read past the stale time computes, as a miss does
	 */
	public function aFailureFromBeforeTheValueWasFreshDoesNotCount(): bool
	{
		$store = $this->staleStore();
		$key = $this->errorKey('older-failure');
		$this->writeErrorKept($store, $key, 'last good');
		$failed = 0;
		$calls = 0;
		
		$this->staleStore()->get($key, $this->failing($failed), 60, stale: 1, staleIfError: 60);
		usleep(20_000);
		// a value computed since: its fresh time ended after that failure, and it has no stale time
		$newer = new Stale('newer', microtime(true) - 0.005, 0, false, errorFor: 60);
		$store instanceof Tags
			? $store->set($key, $newer, 70, [self::POLICY_TAG])
			: $store->set($key, $newer, 70);
		$reader = $this->staleStore();
		$this->capture($reader, $deferred);
		$served = $reader->get($key, $this->counting($calls, 'new'), 60, staleIfError: 60);
		$reader->setDeferrer(null);
		$store->delete($key);
		
		return $failed === 1 && $served === 'new' && $calls === 1 && $deferred === [];
	}
	
	/**
	 * RULE: a refresh of a stale value that fails is remembered when the
	 * value is kept for errors - past its stale time nobody has to compute to
	 * learn that the origin is down; a value kept for no error remembers
	 * nothing
	 */
	public function aFailedRefreshOfAValueKeptForErrorsIsRemembered(): bool
	{
		$store = $this->staleStore();
		$kept = $this->errorKey('refresh-kept');
		$plain = $this->errorKey('refresh-plain');
		$store->delete($kept);
		$store->delete($plain);
		// both inside their stale time; one kept for errors after it
		foreach([$kept => 60, $plain => 0] as $key => $errorFor)
		{
			$stale = new Stale('last good', microtime(true) - 1, 60, false, $errorFor);
			$store instanceof Tags
				? $store->set($key, $stale, 180, [self::POLICY_TAG])
				: $store->set($key, $stale, 180);
		}
		$calls = 0;
		
		$servedKept = $this->staleStore()->get($kept, $this->failing($calls), 60, stale: 60, staleIfError: 60);
		$servedPlain = $this->staleStore()->get($plain, $this->failing($calls), 60, stale: 60);
		$memoLock = $this->staleStore()->getMemoLock();
		$rememberedKept = $memoLock->failedAt($store->itemId($kept));
		$rememberedPlain = $memoLock->failedAt($store->itemId($plain));
		$store->delete($kept);
		$store->delete($plain);
		
		return $servedKept === 'last good' && $servedPlain === 'last good' && $calls === 2
			&& $rememberedKept !== null && $rememberedKept > microtime(true) - 5
			&& $rememberedPlain === null;
	}
	
	/**
	 * A key of its own per run: a failure is remembered for a few seconds, and
	 * the next run must not find the one this run left
	 */
	protected function errorKey(
		string $name,
	): string
	{
		return $this->staleKey($name . '-' . bin2hex(random_bytes(4)));
	}
	
	/**
	 * A resolver that counts its calls and throws
	 */
	protected function failing(
		int &$calls,
	): Closure
	{
		return function() use (&$calls): never
		{
			$calls++;
			
			throw new RuntimeException('the origin is down');
		};
	}
	
	/**
	 * The store under test, trying a failed computation again after $retryMs
	 */
	protected function retrying(
		int $retryMs,
	): KeyValue
	{
		return $this->staleStore()
			->setErrorRetryMs($retryMs);
	}
	
	/**
	 * Writes $value as a value past its ttl and past its stale time, kept for
	 * errors (staleIfError) another minute
	 */
	protected function writeErrorKept(
		KeyValue $store,
		string $key,
		mixed $value,
	): void
	{
		$store->delete($key);
		$kept = new Stale($value, microtime(true) - 10, 1, false, errorFor: 60);
		
		if($store instanceof Tags)
		{
			$store->set($key, $kept, 70, [self::POLICY_TAG]);
			
			return;
		}
		
		$store->set($key, $kept, 70);
	}
	
	/**
	 * The store under test when it has tags
	 */
	protected function policyTagStore(): Tags
	{
		$store = $this->staleStore();
		if($store instanceof Tags === false)
		{
			throw new SkipException('no tags on this store');
		}
		
		return $store;
	}
}
