<?php
declare(strict_types=1);

namespace Ovos\Test\Cache\Store;

use Ovos\Cache\Computed;
use Ovos\Cache\Stale;
use Ovos\Cache\Store\KeyValue;
use Ovos\Cache\Store\KeyValue\Redis as KeyValueRedis;
use Ovos\Cache\Store\KeyValue\Tags;
use Ovos\Test\Exception\SkipException;
use Closure;
use RuntimeException;

use function array_combine;
use function array_count_values;
use function array_map;
use function array_values;
use function bin2hex;
use function count;
use function microtime;
use function random_bytes;
use function usleep;

/**
 * TraitBatch
 *
 * The rules of getMany() every store shares: the misses of a batch are
 * computed by one resolver call, every item is judged and guarded on its
 * own (stale:, soft:, staleIfError:, refresh:, the invalidation guard), one
 * process computes a key and the others wait for it (a lock per key), and on
 * a standalone server a batch costs one round trip warm and three cold. A
 * computation that failed is remembered per key, as get() remembers it: the
 * batches that waited for a key and the batches after serve its kept value
 * without computing, one process tries again per retry time (with the batch's
 * refresh), and a healthy batch looks for no failure.
 * Used with TraitStaleWhileRevalidate and TraitCachePolicy (their helpers)
 *
 * @author Marcin Gil <mg@ovos.at>
 */
trait TraitBatch
{
	protected const string BATCH_TAG = 'batch-tag';
	
	/**
	 * A fresh instance of the store whose client counts its round trips
	 * (CountingConnection) - null where the store has no single standalone
	 * client (APCu, a cluster): the round-trip rules skip it
	 */
	abstract protected function batchProbe(): ?KeyValueRedis;
	
	/**
	 * RULE: a batch serves its hits and computes its misses by one resolver
	 * call, given exactly the missing keys - the answer in the keys' order
	 */
	public function aBatchServesHitsAndComputesMissesInOneResolverCall(): bool
	{
		$store = $this->staleStore();
		[$a, $b, $c, $d, $e] = $keys = $this->batchKeys('mixed', 5);
		$store->set($a, 'A', 60);
		$store->set($c, 'C', 60);
		$store->set($e, 'E', 60);
		$calls = [];
		
		$values = $this->staleStore()->getMany($keys, $this->batchResolver($calls), 60);
		$this->batchDelete($keys);
		
		return $values === ['A', 'computed ' . $b, 'C', 'computed ' . $d, 'E']
			&& $calls === [[$b, $d]];
	}
	
	/**
	 * RULE: a miss in the middle of a batch shifts nothing - every reply is
	 * judged by its key's place, a hit's and a miss's alike (a missing item,
	 * one past its fresh time), so no key is answered with its neighbour's
	 */
	public function aMissInTheMiddleOfABatchShiftsNothing(): bool
	{
		$store = $this->staleStore();
		[$a, $b, $c, $d] = $keys = $this->batchKeys('middle', 4);
		$store->set($a, 'A', 60);
		$this->writeAged($store, $c, 'C past its fresh time');
		$store->set($d, 'D', 60);
		$calls = [];
		
		// a read only first: no lock, no second look to find what it shifted
		$read = $this->staleStore()->getMany($keys);
		$values = $this->staleStore()->getMany($keys, $this->batchResolver($calls), 60);
		$this->batchDelete($keys);
		
		return $read === ['A', null, null, 'D']
			&& $values === ['A', 'computed ' . $b, 'computed ' . $c, 'D']
			&& $calls === [[$b, $c]];
	}
	
	/**
	 * RULE: every miss of a batch is guarded like a single miss - a delete
	 * landing while the resolver computes refuses that key's write, the
	 * others land
	 */
	public function everyMissOfABatchIsGuarded(): bool
	{
		$other = $this->staleStore();
		[$a, $b] = $keys = $this->batchKeys('guarded', 2);
		
		$values = $this->staleStore()->getMany($keys, function(KeyValue $store, array $keys) use ($other, $a, $b): array
		{
			// another process deletes b after this batch missed it
			$other->delete($b);
			
			return [$a => 'A', $b => 'B computed before the delete'];
		}, 60);
		$readA = $this->staleStore()->get($a, queue: false);
		$readB = $this->staleStore()->get($b, queue: false);
		$this->batchDelete($keys);
		
		return $values === ['A', 'B computed before the delete']
			&& $readA === 'A'
			&& $readB === null;
	}
	
	/**
	 * RULE: a tag invalidation landing while a batch computes refuses every
	 * write carrying the tag - the others land
	 */
	public function aTagInvalidationDuringABatchRefusesEveryWriteItReaches(): bool
	{
		$store = $this->batchTagStore();
		$other = $this->staleStore();
		[$a, $b, $c, $d] = $keys = $this->batchKeys('tagged', 4);
		
		$values = $store->getMany($keys, function(KeyValue $store, array $keys) use ($other, $a, $b, $c, $d): array
		{
			// the source changed and another process invalidated the tag
			// after this batch missed
			$other->invalidateTags([self::BATCH_TAG]);
			
			return [
				$a => new Computed('A', tags: [self::BATCH_TAG]),
				$b => new Computed('B', tags: [self::BATCH_TAG]),
				$c => 'C',
				$d => 'D',
			];
		}, 60);
		$reads = array_map(fn(string $key): mixed => $this->staleStore()->get($key, queue: false), $keys);
		$this->batchDelete($keys);
		
		return $values === ['A', 'B', 'C', 'D']
			&& $reads === [null, null, 'C', 'D'];
	}
	
	/**
	 * RULE: the values of a batch past their fresh time are served at once
	 * and refreshed by ONE deferred closure, which computes them by one
	 * resolver call and stores them; a fresh one is served as it is
	 */
	public function aStaleValueInABatchIsServedAndRefreshedOnce(): bool
	{
		$store = $this->staleStore();
		$this->capture($store, $deferred);
		[$a, $b, $c] = $keys = $this->batchKeys('stale', 3);
		$this->writeAged($store, $a, 'old a');
		$this->writeAged($store, $b, 'old b');
		$store->set($c, new Stale('fresh c', microtime(true) + 60, 60), 60);
		$calls = [];
		
		$served = $store->getMany($keys, $this->batchResolver($calls), 60, stale: 60);
		$before = count($calls);
		foreach($deferred as $refresh)
		{
			$refresh();
		}
		$after = array_map(fn(string $key): mixed => $this->staleStore()->get($key, queue: false), [$a, $b]);
		$store->setDeferrer(null);
		$this->batchDelete($keys);
		
		return $served === ['old a', 'old b', 'fresh c']
			&& $before === 0
			&& count($deferred) === 1
			&& $calls === [[$a, $b]]
			&& $after === ['computed ' . $a, 'computed ' . $b];
	}
	
	/**
	 * RULE (probe): inline (no deferrer), a stale key another process
	 * refreshed between the batch's read and its lock is answered with the
	 * refreshed value, as get() answers it, and not computed again
	 */
	public function anInlineRefreshAnswersAValueRefreshedMeanwhile(): bool
	{
		$probe = $this->requireBatchProbe();
		$other = $this->staleStore();
		[$a, $b] = $keys = $this->batchKeys('meanwhile', 2);
		$this->writeAged($other, $a, 'old a');
		$this->writeAged($other, $b, 'old b');
		$client = $probe->getConnection()->counter();
		$client->beforeLock = static function() use ($other, $a): void
		{
			// another process refreshes a after the batch read it stale
			$other->set($a, 'refreshed a', 60);
		};
		
		$values = $probe->getMany($keys, $this->batchResolver($calls), 60, stale: 60);
		$client->beforeLock = null;
		$this->batchDelete($keys);
		
		return $values === ['refreshed a', 'computed ' . $b]
			&& $calls === [[$b]];
	}
	
	/**
	 * RULE: a soft value a tag invalidation reached is served aged in a
	 * batch, and refreshed, as get() serves it
	 */
	public function aSoftlyInvalidatedValueInABatchIsServedAged(): bool
	{
		$store = $this->softStore();
		$this->capture($store, $deferred);
		[$key] = $this->batchKeys('soft', 1);
		$this->writeSoft($key, 'old');
		$this->staleStore()->invalidateTags([self::SOFT_TAG]);
		$calls = [];
		
		$served = $store->getMany([$key], $this->batchResolver($calls), 60, [self::SOFT_TAG], stale: 60, soft: true);
		$before = count($calls);
		foreach($deferred as $refresh)
		{
			$refresh();
		}
		$after = $this->staleStore()->get($key, queue: false);
		$store->setDeferrer(null);
		$this->batchDelete([$key]);
		
		return $served === ['old']
			&& $before === 0
			&& count($deferred) === 1
			&& $after === 'computed ' . $key;
	}
	
	/**
	 * RULE: a batch locks per key - a key another process holds is waited
	 * for, the batch computes its own keys first (resolver call 1), then
	 * what the wait did not bring (call 2): here after the foreign lock
	 * expired unreleased, and stored
	 */
	public function aBatchLocksPerKeyAndWaitsForAForeignLock(): bool
	{
		$store = $this->staleStore();
		$holder = $this->staleStore();
		[$a, $b] = $keys = $this->batchKeys('locked', 2);
		$held = $holder->getMemoLock()->tryLock($this->staleId($holder, $b), 100);
		$calls = [];
		$times = [];
		$started = microtime(true);
		
		$values = $store->getMany($keys, function(KeyValue $store, array $keys) use (&$calls, &$times, $started): array
		{
			$calls[] = $keys;
			$times[] = microtime(true) - $started;
			
			return $this->computedFor($keys);
		}, 60);
		$stored = $this->staleStore()->get($b, queue: false);
		$holder->getMemoLock()->releaseActiveLock($this->staleId($holder, $b));
		$this->batchDelete($keys);
		
		return $held === true
			&& $values === ['computed ' . $a, 'computed ' . $b]
			&& $calls === [[$a], [$b]]
			&& ($times[1] ?? 0) >= 0.1
			&& $stored === 'computed ' . $b;
	}
	
	/**
	 * RULE: the batch resolver may answer a Computed per key - its own TTL,
	 * its own tags, or save: false; save: false and a key it does not
	 * answer store nothing, and their locks are free at once
	 */
	public function aBatchResolverMayAnswerAComputedPerKey(): bool
	{
		$store = $this->staleStore();
		[$short, $tagged, $unsaved, $unanswered] = $keys = $this->batchKeys('computed', 4);
		
		$values = $store->getMany($keys, static fn(KeyValue $store, array $keys): array => [
			$short => new Computed('short', ttl: 5),
			$tagged => new Computed('tagged', tags: [self::BATCH_TAG]),
			$unsaved => new Computed('unsaved', save: false),
		], 60);
		$shortTtl = $this->storedTtlMs($store, $short);
		$taggedTtl = $this->storedTtlMs($store, $tagged);
		$nothing = [
			$this->staleStore()->get($unsaved, queue: false),
			$this->staleStore()->get($unanswered, queue: false),
		];
		$probe = $this->staleStore();
		$free = [
			$probe->getMemoLock()->tryLock($this->staleId($probe, $unsaved)),
			$probe->getMemoLock()->tryLock($this->staleId($probe, $unanswered)),
		];
		$probe->getMemoLock()->releaseActiveLock($this->staleId($probe, $unsaved));
		$probe->getMemoLock()->releaseActiveLock($this->staleId($probe, $unanswered));
		$byTag = true;
		if($store instanceof Tags)
		{
			$this->staleStore()->invalidateTags([self::BATCH_TAG]);
			$byTag = $this->staleStore()->get($tagged, queue: false) === null;
		}
		$this->batchDelete($keys);
		
		return $values === ['short', 'tagged', 'unsaved', null]
			&& $shortTtl > 0 && $shortTtl <= 5000
			&& $taggedTtl > 5000
			&& $nothing === [null, null]
			&& $free === [true, true]
			&& $byTag;
	}
	
	/**
	 * RULE: a batch without a resolver only reads - its misses are null,
	 * and no lock was taken for them
	 */
	public function aBatchWithoutAResolverReadsOnly(): bool
	{
		$store = $this->staleStore();
		[$hit, $miss] = $keys = $this->batchKeys('read-only', 2);
		$store->set($hit, 'H', 60);
		
		$values = $this->staleStore()->getMany($keys);
		$probe = $this->staleStore();
		$free = $probe->getMemoLock()->tryLock($this->staleId($probe, $miss));
		$probe->getMemoLock()->releaseActiveLock($this->staleId($probe, $miss));
		$this->batchDelete($keys);
		
		return $values === ['H', null]
			&& $free === true;
	}
	
	/**
	 * RULE: staleIfError in a batch falls back per key - when the resolver
	 * throws, every key kept for errors gets its kept value and nothing is
	 * thrown; one key without a kept value rethrows, and every lock of the
	 * batch is free
	 */
	public function staleIfErrorInABatchFallsBackPerKey(): bool
	{
		$store = $this->staleStore();
		[$first, $second, $bare] = $keys = $this->batchKeys('error-kept', 3);
		$this->writeErrorKept($store, $first, 'kept first');
		$this->writeErrorKept($store, $second, 'kept second');
		$failing = static function(): never
		{
			throw new RuntimeException('the source is down');
		};
		
		$kept = $this->staleStore()->getMany([$first, $second], $failing, 60, staleIfError: 60);
		$thrown = false;
		try
		{
			$this->staleStore()->getMany([$first, $bare], $failing, 60, staleIfError: 60);
		}
		catch(RuntimeException)
		{
			$thrown = true;
		}
		$free = $this->locksFree([$first, $bare]);
		$this->batchDelete($keys);
		
		return $kept === ['kept first', 'kept second']
			&& $thrown
			&& $free;
	}
	
	/**
	 * RULE: a resolver that throws leaves no lock of the batch behind - the
	 * next caller computes at once, not after the locks' TTL
	 */
	public function aBatchReleasesEveryLockWhenTheResolverThrows(): bool
	{
		$keys = $this->batchKeys('thrown', 2);
		$thrown = false;
		try
		{
			$this->staleStore()->getMany($keys, static function(): never
			{
				throw new RuntimeException('the source is down');
			}, 60);
		}
		catch(RuntimeException)
		{
			$thrown = true;
		}
		$free = $this->locksFree($keys);
		$this->batchDelete($keys);
		
		return $thrown
			&& $free;
	}
	
	/**
	 * RULE: a key given twice is computed once, and answered at both places
	 */
	public function aBatchWithDuplicateKeysComputesEachOnce(): bool
	{
		[$a, $b] = $this->batchKeys('duplicate', 2);
		$calls = [];
		
		$values = $this->staleStore()->getMany([$a, $a, $b], $this->batchResolver($calls), 60);
		$this->batchDelete([$a, $b]);
		
		return $values === ['computed ' . $a, 'computed ' . $a, 'computed ' . $b]
			&& $calls === [[$a, $b]];
	}
	
	/**
	 * RULE: an empty batch touches nothing - no resolver call, and (a store
	 * with a client) not one command sent
	 */
	public function anEmptyBatchTouchesNothing(): bool
	{
		$probe = $this->batchProbe();
		$counter = $probe?->getConnection()->counter();
		$counter?->resetCount();
		$called = false;
		
		$values = ($probe ?? $this->staleStore())->getMany([], function() use (&$called): array
		{
			$called = true;
			
			return [];
		}, 60);
		
		return $values === []
			&& $called === false
			&& ($counter === null || $counter->roundTrips === 0);
	}
	
	/**
	 * RULE: a forced refresh in a batch recomputes every key, the fresh
	 * values too - and a delete landing while it computes refuses that key
	 */
	public function aForcedRefreshInABatchRecomputesEveryKey(): bool
	{
		$store = $this->staleStore();
		$other = $this->staleStore();
		[$a, $b] = $keys = $this->batchKeys('refresh', 2);
		$store->set($a, 'old a', 60);
		$store->set($b, 'old b', 60);
		$calls = [];
		
		$values = $this->staleStore()->getMany($keys, function(KeyValue $store, array $keys) use (&$calls, $other, $b): array
		{
			$calls[] = $keys;
			// another process deletes b while it is recomputed
			$other->delete($b);
			
			return $this->computedFor($keys);
		}, 60, refresh: true);
		$readA = $this->staleStore()->get($a, queue: false);
		$readB = $this->staleStore()->get($b, queue: false);
		$this->batchDelete($keys);
		
		return $values === ['computed ' . $a, 'computed ' . $b]
			&& $calls === [[$a, $b]]
			&& $readA === 'computed ' . $a
			&& $readB === null;
	}
	
	/**
	 * RULE (probe): a batch of hits is read by ONE pipeline - an HMGET per
	 * key in one round trip, none sent on its own
	 */
	public function aBatchReadIsOnePipeline(): bool
	{
		$probe = $this->requireBatchProbe();
		$keys = $this->batchKeys('pipelined', 50);
		$probe->setMany($this->computedFor($keys), 60);
		// warmed: the libraries confirmed, the rules held
		$probe->getMany($keys);
		$counter = $probe->getConnection()->counter();
		$counter->resetCount();
		
		$values = $probe->getMany($keys);
		$sent = $counter->sent;
		$singles = $counter->singles();
		$this->batchDelete($keys);
		
		return $values === array_values($this->computedFor($keys))
			&& $this->pipelinesOf($sent, 'hMget', 50) === 1
			&& (array_count_values($singles)['hMget'] ?? 0) === 0;
	}
	
	/**
	 * RULE (probe): the misses of a batch are written by ONE pipeline - each
	 * guarded write with its lock's release, 50 writes and 50 releases in one
	 * round trip
	 */
	public function aBatchWritesAllItsValuesInOnePipeline(): bool
	{
		$probe = $this->requireBatchProbe();
		$this->batchWarm($probe);
		$keys = $this->batchKeys('written', 50);
		$counter = $probe->getConnection()->counter();
		$counter->resetCount();
		
		$values = $probe->getMany($keys, static fn(KeyValue $store, array $keys): array => array_combine($keys, $keys), 60);
		$sent = $counter->sent;
		$this->batchDelete($keys);
		
		return $values === $keys
			&& $this->pipelinesOf($sent, 'fcall', 100) === 1;
	}
	
	/**
	 * RULE (probe): on a standalone server a batch of a hundred keys costs
	 * one round trip warm (the read) and three cold (the read; the locks
	 * with their second look; the writes with the locks' releases)
	 */
	public function aBatchTakesOneRoundTripWarmAndThreeCold(): bool
	{
		$probe = $this->requireBatchProbe();
		$this->batchWarm($probe);
		$keys = $this->batchKeys('round-trips', 100);
		$counter = $probe->getConnection()->counter();
		$counter->resetCount();
		
		$probe->getMany($keys, static fn(KeyValue $store, array $keys): array => array_combine($keys, $keys), 60);
		$cold = $counter->roundTrips;
		$counter->resetCount();
		$warm = $probe->getMany($keys, static fn(KeyValue $store, array $keys): array => [], 60);
		$warmTrips = $counter->roundTrips;
		$this->batchDelete($keys);
		
		return $warm === $keys
			&& $cold === 3
			&& $warmTrips === 1;
	}
	
	/**
	 * RULE (probe): a batch read that fails on the connection is a miss for every key, and no write follows
	 * it. A key may have been deleted or invalidated without this process seeing it, as for get()
	 * (TraitInvalidationGuard). The keys are deleted.
	 */
	public function aBatchWriteAfterAFailedReadDropsTheKeys(): bool
	{
		$probe = $this->requireBatchProbe();
		$other = $this->staleStore();
		[$a, $b] = $keys = $this->batchKeys('unread', 2);
		$other->set($a, 'old a', 60);
		$probe->getConnection()->counter()->failingReads = 1;
		
		$values = $probe->getMany($keys, function(KeyValue $store, array $keys) use ($other, $a): array
		{
			// the source changes while the values are computed
			$other->delete($a);
			
			return $this->computedFor($keys);
		}, 60, queue: false);
		$read = array_map(fn(string $key): mixed => $this->staleStore()->get($key, queue: false), $keys);
		$this->batchDelete($keys);
		
		return $values === ['computed ' . $a, 'computed ' . $b]
			&& $read === [null, null];
	}
	
	/**
	 * RULE (probe): a forced refresh of a batch whose read failed leaves no outdated value behind. Its writes
	 * could only be refused, so the keys are deleted.
	 */
	public function aForcedBatchRefreshAfterAFailedReadLeavesNoOutdatedValue(): bool
	{
		$probe = $this->requireBatchProbe();
		$other = $this->staleStore();
		[$a, $b] = $keys = $this->batchKeys('refresh-unread', 2);
		$other->set($a, 'outdated a', 60);
		$other->set($b, 'outdated b', 60);
		$probe->getConnection()->counter()->failingReads = 1;
		
		$values = $probe->getMany($keys, $this->batchResolver($calls), 60, refresh: true);
		$read = array_map(fn(string $key): mixed => $this->staleStore()->get($key, queue: false), $keys);
		$this->batchDelete($keys);
		
		return $values === ['computed ' . $a, 'computed ' . $b]
			&& $read === [null, null];
	}
	
	/**
	 * RULE (probe): in a batch read, a key answered by an error reply is a failed read, not a key found
	 * empty. A forced refresh after it leaves no outdated value behind.
	 */
	public function aBatchErrorReplyIsAFailedRead(): bool
	{
		$probe = $this->requireBatchProbe();
		$other = $this->staleStore();
		[$a, $b] = $keys = $this->batchKeys('erring', 2);
		$other->set($a, 'outdated a', 60);
		$other->set($b, 'outdated b', 60);
		$probe->getConnection()->counter()->erringReads = 2;
		
		$values = $probe->getMany($keys, $this->batchResolver($calls), 60, refresh: true);
		$read = array_map(fn(string $key): mixed => $this->staleStore()->get($key, queue: false), $keys);
		$this->batchDelete($keys);
		
		return $values === ['computed ' . $a, 'computed ' . $b]
			&& $read === [null, null];
	}
	
	/**
	 * RULE (probe): the second reads made with a batch's locks that get an error reply are failed reads too,
	 * not keys found empty. The writes after them are refused, and the keys never hold the values computed.
	 */
	public function aBatchLockLookAnsweredByAnErrorReplyIsAFailedRead(): bool
	{
		$probe = $this->requireBatchProbe();
		[$a, $b] = $keys = $this->batchKeys('look-erring', 2);
		// the batch read and the looks made with the locks
		$probe->getConnection()->counter()->erringReads = 4;
		
		$values = $probe->getMany($keys, $this->batchResolver($calls), 60);
		$read = array_map(fn(string $key): mixed => $this->staleStore()->get($key, queue: false), $keys);
		$this->batchDelete($keys);
		
		return $values === ['computed ' . $a, 'computed ' . $b]
			&& $read === [null, null];
	}
	
	/**
	 * RULE: values of a batch that are read stale again before their refresh ran queue no second refresh, and
	 * a key whose refresh get() queued is left out of the batch's refresh. Another refresh would not renew
	 * them any sooner. Once the refresh ran, a stale read queues one again.
	 */
	public function aStaleValueInABatchQueuesOneRefreshUntilItRuns(): bool
	{
		$store = $this->staleStore();
		$this->capture($store, $deferred);
		[$a, $b] = $keys = $this->batchKeys('queued-once', 2);
		$this->writeAged($store, $a, 'old a');
		$this->writeAged($store, $b, 'old b');
		$resolver = $this->batchResolver($calls);
		
		$single = $store->get($a, fn(): string => 'single ' . $a, 60, stale: 60);
		$served = $store->getMany($keys, $resolver, 60, stale: 60);
		$again = $store->getMany($keys, $resolver, 60, stale: 60);
		$queued = count($deferred);
		foreach($deferred as $refresh)
		{
			$refresh();
		}
		$this->writeAged($store, $b, 'aged b');
		$after = $store->getMany([$b], $resolver, 60, stale: 60);
		$store->setDeferrer(null);
		$this->batchDelete($keys);
		
		return $single === 'old a'
			&& $served === ['old a', 'old b']
			&& $again === ['old a', 'old b']
			&& $queued === 2
			&& $calls === [[$b]]
			&& $after === ['aged b']
			&& count($deferred) === 3;
	}
	
	/**
	 * RULE: a batch computation that failed is not repeated by the next
	 * batch - the values kept for errors are served at once, nothing
	 * computed, nothing queued, until the retry time has passed
	 */
	public function aFailedBatchComputationIsNotRepeatedByTheNextBatch(): bool
	{
		$store = $this->staleStore();
		[$a, $b] = $keys = $this->batchKeys('failed', 2);
		$this->writeErrorKept($store, $a, 'kept a');
		$this->writeErrorKept($store, $b, 'kept b');
		
		$first = $this->staleStore()->getMany($keys, $this->batchFailing($calls), 60, stale: 1, staleIfError: 60);
		$reader = $this->staleStore();
		$this->capture($reader, $deferred);
		$second = $reader->getMany($keys, $this->batchFailing($again), 60, stale: 1, staleIfError: 60);
		$reader->setDeferrer(null);
		$this->batchDelete($keys);
		
		return $first === ['kept a', 'kept b'] && $calls === [[$a, $b]]
			&& $second === ['kept a', 'kept b'] && $again === [] && $deferred === [];
	}
	
	/**
	 * RULE: a batch that waited for a key whose computation failed serves the
	 * value kept for errors - it does not take the lock and compute in turn.
	 * Another process holds the key's lock; while the batch waits, that one
	 * fails and remembers it (here: at the batch's second read of the value).
	 * The batch's own miss is computed as ever
	 */
	public function aBatchThatWaitedForAFailedComputationServesTheKeptValue(): bool
	{
		$store = $this->staleStore();
		[$a, $b] = $keys = $this->batchKeys('waited', 2);
		$this->writeRecord($store, $b, new Stale(new ReadSpy('kept b'), microtime(true) - 10, 1, false, errorFor: 60), 70);
		$id = $this->staleId($store, $b);
		// the other process: it holds the lock, and its lock goes when it has failed
		$holder = $this->staleStore()->getMemoLock();
		$held = $holder->tryLock($id, 300);
		ReadSpy::watch(function(int $reads) use ($holder, $id): void
		{
			// the first read is the batch's own; the second is the look after the wait
			if($reads === 2)
			{
				$holder->rememberFailure($id, 3000);
			}
		});
		
		try
		{
			$values = $this->staleStore()->getMany($keys, $this->batchResolver($calls), 60, stale: 1, staleIfError: 60);
			
			return $held
				&& ($values[0] ?? null) === 'computed ' . $a
				&& ($values[1] ?? null) instanceof ReadSpy && $values[1]->value === 'kept b'
				&& $calls === [[$a]];
		}
		finally
		{
			ReadSpy::watch(null);
			$holder->releaseActiveLock($id);
			$this->batchDelete($keys);
		}
	}
	
	/**
	 * RULE: once the retry time has passed, one process tries the keys of a
	 * batch again - by ONE deferred closure and one resolver call, as the
	 * batch's refresh, and the batch is answered with the kept values at
	 * once; a failure of that attempt is remembered anew
	 */
	public function onceTheRetryTimeHasPassedOneBatchTriesAgain(): bool
	{
		$store = $this->staleStore();
		[$a, $b] = $keys = $this->batchKeys('retry', 2);
		$this->writeErrorKept($store, $a, 'kept a');
		$this->writeErrorKept($store, $b, 'kept b');
		
		$this->retrying(50)->getMany($keys, $this->batchFailing($first), 60, stale: 1, staleIfError: 60);
		usleep(60_000);
		$reader = $this->retrying(50);
		$this->capture($reader, $deferred);
		$resolver = $this->batchFailing($calls);
		$served = $reader->getMany($keys, $resolver, 60, stale: 1, staleIfError: 60);
		$callsBeforeTheRefresh = $calls;
		$queued = count($deferred);
		foreach($deferred as $refresh)
		{
			$refresh();
		}
		$callsAfterTheRefresh = $calls;
		// right after the attempt failed: not yet again
		$this->capture($reader, $deferred);
		$again = $reader->getMany($keys, $resolver, 60, stale: 1, staleIfError: 60);
		$reader->setDeferrer(null);
		$this->batchDelete($keys);
		
		return $first === [[$a, $b]]
			&& $served === ['kept a', 'kept b'] && $callsBeforeTheRefresh === [] && $queued === 1
			&& $callsAfterTheRefresh === [[$a, $b]]
			&& $again === ['kept a', 'kept b'] && $calls === [[$a, $b]] && $deferred === [];
	}
	
	/**
	 * RULE: a batch beside that attempt serves the kept value at once - the
	 * key another process holds the lock of is not computed and not waited
	 * for; the key whose lock is free is this batch's to try
	 */
	public function aBatchBesideTheNextAttemptServesTheKeptValue(): bool
	{
		$store = $this->staleStore();
		[$a, $b] = $keys = $this->batchKeys('retry-lost', 2);
		$this->writeErrorKept($store, $a, 'kept a');
		$this->writeErrorKept($store, $b, 'kept b');
		
		$this->retrying(50)->getMany($keys, $this->batchFailing($first), 60, stale: 1, staleIfError: 60);
		usleep(60_000);
		$id = $this->staleId($store, $b);
		$holder = $this->staleStore()->getMemoLock();
		$held = $holder->tryLock($id, 300);
		
		try
		{
			$started = microtime(true);
			$served = $this->retrying(50)->getMany($keys, $this->batchFailing($calls), 60, stale: 1, staleIfError: 60);
			$waited = microtime(true) - $started;
			
			return $held && $first === [[$a, $b]]
				&& $served === ['kept a', 'kept b'] && $calls === [[$a]]
				&& $waited < 0.25;
		}
		finally
		{
			$holder->releaseActiveLock($id);
			$this->batchDelete($keys);
		}
	}
	
	/**
	 * RULE: an attempt that succeeds stores the new values - the batch that
	 * made it is answered with the kept values (the attempt runs after the
	 * response), and the next batch is a hit
	 */
	public function aBatchAttemptThatSucceedsStoresTheNewValues(): bool
	{
		$store = $this->staleStore();
		[$a, $b] = $keys = $this->batchKeys('retry-succeeds', 2);
		$this->writeErrorKept($store, $a, 'kept a');
		$this->writeErrorKept($store, $b, 'kept b');
		
		$this->retrying(50)->getMany($keys, $this->batchFailing($failed), 60, stale: 1, staleIfError: 60);
		usleep(60_000);
		$reader = $this->retrying(50);
		$this->capture($reader, $deferred);
		$served = $reader->getMany($keys, $this->batchResolver($calls), 60, stale: 1, staleIfError: 60);
		$queued = count($deferred);
		foreach($deferred as $refresh)
		{
			$refresh();
		}
		$reader->setDeferrer(null);
		$read = $this->staleStore()->getMany($keys);
		$this->batchDelete($keys);
		
		return $failed === [[$a, $b]]
			&& $served === ['kept a', 'kept b'] && $queued === 1
			&& $calls === [[$a, $b]]
			&& $read === ['computed ' . $a, 'computed ' . $b];
	}
	
	/**
	 * RULE: an attempt that succeeds forgets the failures - values the
	 * resolver does not store (save: false) leave the next batch to compute
	 * them, as with no failure, instead of the kept values until the next
	 * attempt
	 */
	public function aBatchAttemptWhoseValuesAreNotStoredForgetsTheFailures(): bool
	{
		$store = $this->staleStore();
		[$a, $b] = $keys = $this->batchKeys('retry-unsaved', 2);
		$this->writeErrorKept($store, $a, 'kept a');
		$this->writeErrorKept($store, $b, 'kept b');
		$calls = 0;
		$unsaved = function(KeyValue $store, array $keys) use (&$calls): array
		{
			$calls++;
			$values = [];
			foreach($keys as $key)
			{
				$values[$key] = new Computed('new ' . $key, save: false);
			}
			
			return $values;
		};
		
		$this->retrying(50)->getMany($keys, $this->batchFailing($failed), 60, stale: 1, staleIfError: 60);
		usleep(60_000);
		// the attempt, after the response: computed, not stored
		$reader = $this->retrying(50);
		$this->capture($reader, $deferred);
		$served = $reader->getMany($keys, $unsaved, 60, stale: 1, staleIfError: 60);
		foreach($deferred as $refresh)
		{
			$refresh();
		}
		$reader->setDeferrer(null);
		// within the retry time of that attempt: computed, as with no failure
		$next = $this->retrying(50)->getMany($keys, $unsaved, 60, stale: 1, staleIfError: 60);
		$this->batchDelete($keys);
		
		return $failed === [[$a, $b]]
			&& $served === ['kept a', 'kept b'] && $calls === 2
			&& $next === ['new ' . $a, 'new ' . $b];
	}
	
	/**
	 * RULE: a failure counts for the values it was about - one remembered
	 * before a value's fresh time ended belongs to a value replaced since,
	 * and the batch past the stale time computes, as for any miss
	 */
	public function aBatchFailureFromBeforeTheValuesWereFreshDoesNotCount(): bool
	{
		$store = $this->staleStore();
		[$a, $b] = $keys = $this->batchKeys('older-failure', 2);
		$this->writeErrorKept($store, $a, 'kept a');
		$this->writeErrorKept($store, $b, 'kept b');
		
		$this->staleStore()->getMany($keys, $this->batchFailing($failed), 60, stale: 1, staleIfError: 60);
		usleep(20_000);
		// values computed since: their fresh time ended after that failure, and they have no stale time
		foreach($keys as $key)
		{
			$this->writeRecord($store, $key, new Stale('newer', microtime(true) - 0.005, 0, false, errorFor: 60), 70);
		}
		$reader = $this->staleStore();
		$this->capture($reader, $deferred);
		$served = $reader->getMany($keys, $this->batchResolver($calls), 60, staleIfError: 60);
		$reader->setDeferrer(null);
		$this->batchDelete($keys);
		
		return $failed === [[$a, $b]]
			&& $served === ['computed ' . $a, 'computed ' . $b]
			&& $calls === [[$a, $b]] && $deferred === [];
	}
	
	/**
	 * RULE: a batch refresh of stale values that fails is remembered for the
	 * values kept for errors - past their stale time nobody has to compute to
	 * learn that the origin is down; a value kept for no error remembers
	 * nothing
	 */
	public function aFailedBatchRefreshOfValuesKeptForErrorsIsRemembered(): bool
	{
		$store = $this->staleStore();
		[$kept, $plain] = $keys = $this->batchKeys('refresh-kept', 2);
		// both inside their stale time; one kept for errors after it
		foreach([$kept => 60, $plain => 0] as $key => $errorFor)
		{
			$this->writeRecord($store, $key, new Stale('last good', microtime(true) - 1, 60, false, $errorFor), 180);
		}
		
		$served = $this->staleStore()->getMany($keys, $this->batchFailing($calls), 60, stale: 60, staleIfError: 60);
		$memoLock = $this->staleStore()->getMemoLock();
		$rememberedKept = $memoLock->failedAt($this->staleId($store, $kept));
		$rememberedPlain = $memoLock->failedAt($this->staleId($store, $plain));
		$this->batchDelete($keys);
		
		return $served === ['last good', 'last good'] && $calls === [[$kept, $plain]]
			&& $rememberedKept !== null && $rememberedKept > microtime(true) - 5
			&& $rememberedPlain === null;
	}
	
	/**
	 * RULE: a batch judges each key on its own - a key kept for errors whose
	 * computation failed a moment ago is answered with the kept value, a
	 * fresh one as it is, and a plain miss is computed, alone, and stored
	 */
	public function aBatchMixingAFailedAFreshAndAMissingKeyAnswersEach(): bool
	{
		$store = $this->staleStore();
		[$kept, $fresh, $missing] = $keys = $this->batchKeys('mixed-kept', 3);
		$this->writeErrorKept($store, $kept, 'kept');
		$store->set($fresh, 'fresh', 60);
		
		$this->staleStore()->getMany([$kept], $this->batchFailing($failed), 60, stale: 1, staleIfError: 60);
		$reader = $this->staleStore();
		$this->capture($reader, $deferred);
		$values = $reader->getMany($keys, $this->batchResolver($calls), 60, stale: 1, staleIfError: 60);
		$reader->setDeferrer(null);
		$stored = $this->staleStore()->getMany($keys);
		$this->batchDelete($keys);
		
		return $failed === [[$kept]]
			&& $values === ['kept', 'fresh', 'computed ' . $missing]
			&& $calls === [[$missing]] && $deferred === []
			&& $stored === [null, 'fresh', 'computed ' . $missing];
	}
	
	/**
	 * RULE (probe): a healthy batch looks for no failure - with
	 * staleIfError: given, a batch of misses, of hits and of stale values
	 * (refreshed inline) sends no GET and no MGET, and costs the round trips
	 * it cost before: three cold, one warm
	 */
	public function aHealthyBatchLooksForNoFailure(): bool
	{
		$probe = $this->requireBatchProbe();
		$this->batchWarm($probe);
		$keys = $this->batchKeys('healthy', 20);
		$resolver = static fn(KeyValue $store, array $keys): array => array_combine($keys, $keys);
		$counter = $probe->getConnection()->counter();
		
		$counter->resetCount();
		$computed = $probe->getMany($keys, $resolver, 60, stale: 60, staleIfError: 60);
		$cold = $counter->sent;
		$counter->resetCount();
		$hits = $probe->getMany($keys, $resolver, 60, stale: 60, staleIfError: 60);
		$warm = $counter->sent;
		// past their fresh time, inside their stale time, kept for errors after it
		$other = $this->staleStore();
		foreach($keys as $key)
		{
			$this->writeRecord($other, $key, new Stale('old', microtime(true) - 1, 60, false, 60), 180);
		}
		$counter->resetCount();
		$refreshed = $probe->getMany($keys, $resolver, 60, stale: 60, staleIfError: 60);
		$stale = $counter->sent;
		$this->batchDelete($keys);
		
		return $computed === $keys && $hits === $keys && $refreshed === $keys
			&& count($cold) === 3 && count($warm) === 1
			&& $this->failureLooks([...$cold, ...$warm, ...$stale]) === 0;
	}
	
	/**
	 * RULE (probe): a batch with values past their stale time, kept for
	 * errors, looks for their failures in ONE round trip - one MGET for them
	 * all, no GET per key - and, none remembered, computes them as misses:
	 * the three round trips of a cold batch and that one
	 */
	public function aBatchWithValuesKeptForErrorsLooksForTheirFailuresOnce(): bool
	{
		$probe = $this->requireBatchProbe();
		$this->batchWarm($probe);
		$keys = $this->batchKeys('kept-look', 20);
		$other = $this->staleStore();
		foreach($keys as $key)
		{
			$this->writeErrorKept($other, $key, 'kept');
		}
		$counter = $probe->getConnection()->counter();
		$counter->resetCount();
		
		$values = $probe->getMany($keys, static fn(KeyValue $store, array $keys): array => array_combine($keys, $keys), 60, stale: 1, staleIfError: 60);
		$sent = $counter->sent;
		$this->batchDelete($keys);
		
		return $values === $keys
			&& $this->pipelinesOf($sent, 'mGet', 1) === 1
			&& $this->failureLooks($sent) === 1
			&& count($sent) === 4;
	}
	
	/**
	 * Keys of their own for one rule
	 *
	 * @return list<string>
	 */
	protected function batchKeys(
		string $name,
		int $count,
	): array
	{
		$run = bin2hex(random_bytes(4));
		$keys = [];
		for($index = 0; $index < $count; $index++)
		{
			$keys[] = 'batch-' . $name . '-' . $index . '-' . $run;
		}
		
		return $keys;
	}
	
	/**
	 * A batch resolver answering "computed <key>" for every key, recording
	 * the keys of each call in $calls
	 */
	protected function batchResolver(
		?array &$calls,
	): Closure
	{
		$calls = [];
		
		return function(KeyValue $store, array $keys) use (&$calls): array
		{
			$calls[] = $keys;
			
			return $this->computedFor($keys);
		};
	}
	
	/**
	 * key => "computed <key>"
	 *
	 * @return array<string, string>
	 */
	protected function computedFor(
		array $keys,
	): array
	{
		$values = [];
		foreach($keys as $key)
		{
			$values[$key] = 'computed ' . $key;
		}
		
		return $values;
	}
	
	protected function batchDelete(
		array $keys,
	): void
	{
		$store = $this->staleStore();
		foreach($keys as $key)
		{
			$store->delete($key);
		}
	}
	
	/**
	 * Whether every key's lock is free - taken at once by another instance,
	 * then released
	 */
	protected function locksFree(
		array $keys,
	): bool
	{
		$probe = $this->staleStore();
		$free = true;
		foreach($keys as $key)
		{
			$id = $this->staleId($probe, $key);
			$free = $probe->getMemoLock()->tryLock($id) && $free;
			$probe->getMemoLock()->releaseActiveLock($id);
		}
		
		return $free;
	}
	
	/**
	 * A batch resolver that throws, recording the keys of each call in $calls
	 */
	protected function batchFailing(
		?array &$calls,
	): Closure
	{
		$calls = [];
		
		return function(KeyValue $store, array $keys) use (&$calls): never
		{
			$calls[] = $keys;
			
			throw new RuntimeException('the origin is down');
		};
	}
	
	/**
	 * Writes a record as it is - a value aged by hand
	 */
	protected function writeRecord(
		KeyValue $store,
		string $key,
		Stale $record,
		int $ttl,
	): void
	{
		if($store instanceof Tags)
		{
			$store->set($key, $record, $ttl, [static::BATCH_TAG]);
			
			return;
		}
		
		$store->set($key, $record, $ttl);
	}
	
	/**
	 * How many looks for a remembered failure were sent: the GETs and MGETs,
	 * which a store sends for nothing else in a batch
	 *
	 * @param list<list<string>> $sent
	 */
	protected function failureLooks(
		array $sent,
	): int
	{
		$looks = 0;
		foreach($sent as $commands)
		{
			$counts = array_count_values($commands);
			$looks+= ($counts['get'] ?? 0) + ($counts['mGet'] ?? 0);
		}
		
		return $looks;
	}
	
	/**
	 * The store under test when it has tags
	 */
	protected function batchTagStore(): Tags
	{
		$store = $this->staleStore();
		if($store instanceof Tags === false)
		{
			throw new SkipException('no tags: no tag invalidation to land during a batch');
		}
		
		return $store;
	}
	
	protected function requireBatchProbe(): KeyValueRedis
	{
		$probe = $this->batchProbe();
		if($probe === null)
		{
			throw new SkipException('no single standalone client to count the round trips of');
		}
		
		return $probe;
	}
	
	/**
	 * A batch through the probe first: the libraries confirmed, the rules
	 * held - a later batch counts its own round trips only
	 */
	protected function batchWarm(
		KeyValue $probe,
	): void
	{
		$keys = $this->batchKeys('warm', 2);
		$probe->getMany($keys, static fn(KeyValue $store, array $keys): array => array_combine($keys, $keys), 60);
		$this->batchDelete($keys);
	}
	
	/**
	 * How many round trips carried exactly $count commands $command (and
	 * possibly others)
	 *
	 * @param list<list<string>> $sent
	 */
	protected function pipelinesOf(
		array $sent,
		string $command,
		int $count,
	): int
	{
		$found = 0;
		foreach($sent as $commands)
		{
			if((array_count_values($commands)[$command] ?? 0) === $count)
			{
				$found++;
			}
		}
		
		return $found;
	}
}
