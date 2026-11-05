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

/**
 * TraitBatch
 *
 * The rules of getMany() every store shares: the misses of a batch are
 * computed by one resolver call, every item is judged and guarded on its
 * own (stale:, soft:, staleIfError:, refresh:, the invalidation guard), one
 * process computes a key and the others wait for it (a lock per key), and on
 * a standalone server a batch costs one round trip warm and three cold.
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
