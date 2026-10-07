<?php
declare(strict_types=1);

namespace Ovos\Test\Cache\Store;

use Ovos\Cache\Store\KeyValue\Redis as KeyValueRedis;

use function array_keys;
use function bin2hex;
use function microtime;
use function random_bytes;
use function usleep;

/**
 * TraitInvalidationGuard
 *
 * The invalidation guard's rules (see KeyValue::rememberMiss()), shared by
 * the test of every Redis store: a value computed before another process
 * invalidated it is never served after it - whatever the store's way to make
 * sure (a refused write, an item read stale) - while a write-through, the
 * store's own invalidation and a recomputation older than the window write
 * as they did before the guard. Two store instances stand for two processes:
 * one misses and recomputes, the other invalidates in between.
 *
 * The using class provides guardStore()
 *
 * @author Marcin Gil <mg@ovos.at>
 */
trait TraitInvalidationGuard
{
	protected const string GUARD_KEY = 'guard-item';
	
	protected const string GUARD_TAG = 'guard-tag';
	
	/**
	 * A fresh instance of the store under test - another process
	 */
	abstract protected function guardStore(
		array $storeOptions = [],
	): KeyValueRedis;
	
	/**
	 * RULE: a value computed before another process deleted its key is never
	 * served after it - the write that follows the miss is refused - and the
	 * next reader's recomputation lands as before
	 */
	public function aWriteAfterAnotherDeleteIsRefused(): bool
	{
		$reader = $this->guardStore();
		$writer = $this->guardStore();
		$writer->delete(self::GUARD_KEY);
		
		$missed = $reader->get(self::GUARD_KEY, queue: false) === null;
		$writer->delete(self::GUARD_KEY);
		$written = $reader->set(self::GUARD_KEY, 'stale', 60);
		$read = $this->guardStore()->get(self::GUARD_KEY, queue: false);
		
		$next = $this->guardStore();
		$next->get(self::GUARD_KEY, queue: false);
		$landed = $next->set(self::GUARD_KEY, 'fresh', 60);
		$after = $this->guardStore()->get(self::GUARD_KEY, queue: false);
		$writer->delete(self::GUARD_KEY);
		
		return $missed && $written === false && $read === null
			&& $landed && $after === 'fresh';
	}
	
	/**
	 * RULE: a write with no miss of its own before it is a write-through -
	 * written, even right after another process deleted the key; a hit
	 * forgets the miss before it
	 */
	public function aWriteThroughIsWritten(): bool
	{
		$own = $this->guardStore();
		$other = $this->guardStore();
		
		$other->delete(self::GUARD_KEY);
		$through = $own->set(self::GUARD_KEY, 'through', 60)
			&& $this->guardStore()->get(self::GUARD_KEY, queue: false) === 'through';
		
		$other->delete(self::GUARD_KEY);
		$own->get(self::GUARD_KEY, queue: false);
		$other->set(self::GUARD_KEY, 'filled', 60);
		$hit = $own->get(self::GUARD_KEY, queue: false) === 'filled';
		$other->delete(self::GUARD_KEY);
		$again = $own->set(self::GUARD_KEY, 'again', 60);
		$read = $this->guardStore()->get(self::GUARD_KEY, queue: false);
		$other->delete(self::GUARD_KEY);
		
		return $through && $hit && $again && $read === 'again';
	}
	
	/**
	 * RULE: a store's own delete after its miss does not refuse its own write
	 * - an invalidation in the process's own program order is no race
	 */
	public function anOwnDeleteDoesNotRefuseTheOwnWrite(): bool
	{
		$own = $this->guardStore();
		$own->delete(self::GUARD_KEY);
		
		$own->get(self::GUARD_KEY, queue: false);
		$own->delete(self::GUARD_KEY);
		$written = $own->set(self::GUARD_KEY, 'own', 60);
		$read = $this->guardStore()->get(self::GUARD_KEY, queue: false);
		$own->delete(self::GUARD_KEY);
		
		return $written && $read === 'own';
	}
	
	/**
	 * RULE: a store's own tag invalidation leaves its other misses guarded -
	 * only values carrying the tag are judged by it; an unrelated key another
	 * process deleted meanwhile is still refused
	 */
	public function anOwnTagInvalidationKeepsTheOtherMissesGuarded(): bool
	{
		$reader = $this->guardStore();
		$writer = $this->guardStore();
		$writer->delete(self::GUARD_KEY);
		
		$reader->get(self::GUARD_KEY, queue: false);
		$reader->invalidateTags(['guard-tag-other']);
		$writer->delete(self::GUARD_KEY);
		$reader->set(self::GUARD_KEY, 'stale', 60);
		$read = $this->guardStore()->get(self::GUARD_KEY, queue: false);
		$writer->delete(self::GUARD_KEY);
		
		return $read === null;
	}
	
	/**
	 * RULE: a refused write still releases the stampede lock - the next
	 * reader takes it at once and computes, it does not wait out the lock
	 */
	public function aRefusedWriteReleasesTheLock(): bool
	{
		$reader = $this->guardStore();
		$writer = $this->guardStore();
		if($reader->isQueueEnabled() === false)
		{
			return true; // no lock to release
		}
		$key = $this->guardKey('lock');
		
		$reader->get($key);
		$writer->delete($key);
		$written = $reader->set($key, 'stale', 60);
		$started = microtime(true);
		$value = $this->guardStore()->get($key, fn(): string => 'fresh', 60, queueLockTtlMs: 5000);
		$waited = microtime(true) - $started;
		$writer->delete($key);
		
		return $written === false && $value === 'fresh' && $waited < 1.0;
	}
	
	/**
	 * RULE: a value carrying every tag of a matching-all invalidation is never
	 * served when it was computed before it
	 */
	public function aWriteAfterAMatchingAllInvalidationIsNeverServed(): bool
	{
		$reader = $this->guardStore();
		$writer = $this->guardStore();
		$key = $this->guardKey('all');
		$tags = [self::GUARD_TAG . '-a', self::GUARD_TAG . '-b'];
		
		$reader->get($key, queue: false);
		$writer->invalidateTags($tags, KeyValueRedis::MATCHING_ALL);
		$reader->set($key, 'stale', 60, $tags);
		$read = $this->guardStore()->get($key, queue: false);
		$writer->delete($key);
		
		return $read === null;
	}
	
	/**
	 * RULE: a value written with a TTL shorter than a tombstone's window does
	 * not take the invalidation mark with it - its data expires at the TTL,
	 * the mark stays, and a recomputation that missed before the delete is
	 * still refused after the short item is gone
	 */
	public function aShortLivedWriteKeepsTheMark(): bool
	{
		$early = $this->guardStore();
		$writer = $this->guardStore();
		$late = $this->guardStore();
		$key = $this->guardKey('short');
		$id = $writer->prefix($key, $writer->getType());
		
		$early->get($key, queue: false);
		$writer->delete($key);
		$late->get($key, queue: false);
		$late->set($key, 'short', 1);
		usleep(1300000);
		$expired = $this->guardStore()->get($key, queue: false) === null;
		$marked = (int)$writer->getClient()->hExists($id, KeyValueRedis::KEY_EPOCH) === 1;
		$early->set($key, 'stale', 60);
		$read = $this->guardStore()->get($key, queue: false);
		$writer->delete($key);
		
		return $expired && $marked && $read === null;
	}
	
	/**
	 * RULE: a write-through marks the key - a recomputation whose miss came
	 * before it is refused, and the write-through's value stays
	 */
	public function aWriteThroughRefusesAnOlderRecomputation(): bool
	{
		$reader = $this->guardStore();
		$writer = $this->guardStore();
		$key = $this->guardKey('through');
		
		$reader->get($key, queue: false);
		$writer->set($key, 'new', 60);
		$written = $reader->set($key, 'stale', 60);
		$read = $this->guardStore()->get($key, queue: false);
		$writer->delete($key);
		
		return $written === false && $read === 'new';
	}
	
	/**
	 * RULE: delete() reports an item - deleting a tombstone again is no item
	 */
	public function aDeleteReportsAnItemNotATombstone(): bool
	{
		$store = $this->guardStore();
		$key = $this->guardKey('report');
		
		$store->set($key, 'x', 60);
		$first = $store->delete($key);
		$second = $store->delete($key);
		
		return $first && $second === false;
	}
	
	/**
	 * RULE: a window of 0 switches the guard off - a delete leaves no key, and
	 * the write that follows a miss lands whatever happened meanwhile
	 */
	public function theGuardSwitchesOff(): bool
	{
		$reader = $this->guardStore(['invalidation_window_ms' => 0]);
		$writer = $this->guardStore(['invalidation_window_ms' => 0]);
		$key = $this->guardKey('off');
		$id = $writer->prefix($key, $writer->getType());
		
		$writer->set($key, 'x', 60);
		$writer->delete($key);
		$gone = (int)$writer->getClient()->exists($id) === 0;
		$reader->get($key, queue: false);
		$writer->delete($key);
		$written = $reader->set($key, 'unguarded', 60);
		$read = $this->guardStore()->get($key, queue: false);
		$writer->delete($key);
		
		return $gone && $written && $read === 'unguarded';
	}
	
	/**
	 * A key of its own for one rule - a fresh one, no tombstone of an earlier
	 * run in it
	 */
	protected function guardKey(
		string $name,
	): string
	{
		return self::GUARD_KEY . ':' . $name . ':' . bin2hex(random_bytes(4));
	}
	
	/**
	 * RULE: a deleted key holds its tombstone for the window - the token
	 * alone, no data - which every reader takes for a miss; and a write over a
	 * tombstone does not inherit the window as its expiry
	 */
	public function aTombstoneIsAMissForTheWindowOnly(): bool
	{
		$store = $this->guardStore(['invalidation_window_ms' => 300]);
		$client = $store->getClient();
		$id = $store->prefix(self::GUARD_KEY, $store->getType());
		
		$store->set(self::GUARD_KEY, 'x', 60);
		$store->delete(self::GUARD_KEY);
		$fields = array_keys((array)$client->hGetAll($id));
		$ttl = (int)$client->pttl($id);
		$miss = $this->guardStore()->get(self::GUARD_KEY, queue: false) === null;
		usleep(450000);
		$gone = (int)$client->exists($id) === 0;
		
		// the item's own expiry: none, or (the versioned stores) the retention
		$store->delete(self::GUARD_KEY);
		$store->set(self::GUARD_KEY, 'kept');
		$kept = (int)$client->pttl($id);
		$store->delete(self::GUARD_KEY);
		
		return $fields === [KeyValueRedis::KEY_EPOCH]
			&& $ttl > 0 && $ttl <= 300
			&& $miss && $gone
			&& ($kept === -1 || $kept > 300);
	}
	
	/**
	 * RULE: only a cache item becomes a tombstone - a raw value a caller keeps
	 * under the store's prefix (a counter) is removed as before, so its next
	 * INCR still works (a hash there would answer WRONGTYPE for the window)
	 */
	public function aRawValueIsRemovedNotTombstoned(): bool
	{
		$store = $this->guardStore();
		$client = $store->getClient();
		$key = self::GUARD_KEY . ':counter';
		$id = $store->prefix($key, $store->getType());
		
		$client->set($id, '5');
		$store->delete($key);
		$gone = (int)$client->exists($id) === 0;
		$counted = (int)$client->incr($id) === 1;
		$client->del($id);
		
		return $gone && $counted;
	}
	
	/**
	 * RULE: a recomputation older than the window goes unguarded again - the
	 * guard covers the window, no longer
	 */
	public function aMissOlderThanTheWindowGoesUnguarded(): bool
	{
		// the writer's tombstone outlives the reader's window: only the age of
		// the READER's miss lets the write through
		$reader = $this->guardStore(['invalidation_window_ms' => 100]);
		$writer = $this->guardStore(['invalidation_window_ms' => 10000]);
		$writer->delete(self::GUARD_KEY);
		
		$reader->get(self::GUARD_KEY, queue: false);
		$writer->delete(self::GUARD_KEY);
		usleep(250000);
		$written = $reader->set(self::GUARD_KEY, 'late', 60);
		$read = $this->guardStore()->get(self::GUARD_KEY, queue: false);
		$writer->delete(self::GUARD_KEY);
		
		return $written && $read === 'late';
	}
	
	/**
	 * RULE: a value computed before another process invalidated one of its
	 * tags is never served after it (refused, or read stale), and a value
	 * computed after the invalidation is
	 */
	public function aWriteAfterATagInvalidationIsNeverServed(): bool
	{
		$reader = $this->guardStore();
		$writer = $this->guardStore();
		$writer->delete(self::GUARD_KEY);
		
		$reader->get(self::GUARD_KEY, queue: false);
		$writer->invalidateTags([self::GUARD_TAG]);
		$reader->set(self::GUARD_KEY, 'stale', 60, [self::GUARD_TAG]);
		$read = $this->guardStore()->get(self::GUARD_KEY, queue: false);
		
		$next = $this->guardStore();
		$next->get(self::GUARD_KEY, queue: false);
		$next->set(self::GUARD_KEY, 'fresh', 60, [self::GUARD_TAG]);
		$after = $this->guardStore()->get(self::GUARD_KEY, queue: false);
		$writer->delete(self::GUARD_KEY);
		
		return $read === null && $after === 'fresh';
	}
	
	/**
	 * RULE: a value computed before another process cleared the store is
	 * never served after it
	 */
	public function aWriteAfterAClearIsNeverServed(): bool
	{
		$reader = $this->guardStore();
		$writer = $this->guardStore();
		$writer->delete(self::GUARD_KEY);
		
		$reader->get(self::GUARD_KEY, queue: false);
		$writer->clear();
		$reader->set(self::GUARD_KEY, 'stale', 60);
		$read = $this->guardStore()->get(self::GUARD_KEY, queue: false);
		$writer->delete(self::GUARD_KEY);
		
		return $read === null;
	}
}
