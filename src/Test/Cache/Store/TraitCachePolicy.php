<?php
declare(strict_types=1);

namespace Ovos\Test\Cache\Store;

use Ovos\Cache\Computed;
use Ovos\Cache\Stale;
use Ovos\Cache\Store\KeyValue;
use Ovos\Cache\Store\KeyValue\Tags;
use Ovos\Cache\Store\RedisVersioned;
use Ovos\Test\Exception\SkipException;
use ArgumentCountError;
use InvalidArgumentException;
use RuntimeException;

use function count;
use function microtime;
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
 * computation fails; invalidateTags(hard: true) is a miss at once, a soft
 * value too. Used with TraitStaleWhileRevalidate (its helpers)
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
		
		$value = $store->get($key, fn() => new Computed('short-lived', ttl: 1), 60);
		$hit = $this->staleStore()->get($key, queue: false);
		usleep(2100000);
		$expired = $this->staleStore()->get($key, queue: false);
		$store->delete($key);
		
		return $value === 'short-lived'
			&& $hit === 'short-lived'
			&& $expired === null;
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
		usleep(2600000);
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
