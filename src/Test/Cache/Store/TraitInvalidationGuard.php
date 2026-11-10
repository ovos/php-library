<?php
declare(strict_types=1);

namespace Ovos\Test\Cache\Store;

use Ovos\Cache\Compressor;
use Ovos\Cache\MemoLock\Redis as MemoLock;
use Ovos\Cache\Serializer;
use Ovos\Cache\Stale;
use Ovos\Cache\Store\KeyValue\Redis as KeyValueRedis;
use Ovos\Cache\Store\RedisVersioned;
use Ovos\Test\Exception\SkipException;
use Closure;
use RuntimeException;

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
 * store's own invalidation and a deliberate set() after a read older than the
 * window write as they did before the guard (on a tag-index store, a
 * recomputation older than the window too). Two store instances stand for two
 * processes: one misses and recomputes, the other invalidates in between.
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
	 * Waits until $done() says the server got there, bounded
	 * (TraitStaleWhileRevalidate)
	 */
	abstract protected function awaitServer(
		Closure $done,
		int $boundMs = 5000,
	): bool;
	
	/**
	 * Whether the server expired the data field of every item of $keys
	 * (TraitStaleWhileRevalidate)
	 */
	abstract protected function dataExpired(
		KeyValueRedis $store,
		array $keys,
	): bool;
	
	/**
	 * A fresh instance whose client counts its round trips - and fails
	 * reads when told (CountingClient); null where the store has no single
	 * standalone client (TraitBatch)
	 */
	abstract protected function batchProbe(): ?KeyValueRedis;
	
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
		// the short item's data expired by the server (the key stays: the mark's)
		$this->awaitServer(fn(): bool => $this->dataExpired($writer, [$key]));
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
	 * RULE: a value that does not decode - its class gone in a deploy, a frame
	 * that does not decompress - is a miss the guard remembers: a delete made
	 * while it is recomputed refuses the write, the next reader misses
	 */
	public function aValueThatDoesNotDecodeIsAGuardedMiss(): bool
	{
		$writer = $this->guardStore();
		
		foreach([
			Serializer::PREFIX_SERIALIZE . 'O:18:"NoSuchClassAnyMore":0:{}',
			'gz' . Compressor::PREFIX_COMPRESS . 'garbage',
		] as $index => $payload)
		{
			$key = $this->guardKey('undecodable-' . $index);
			$writer->set($key, 'old', 60);
			$writer->getClient()->hSet($writer->itemId($key), KeyValueRedis::KEY_DATA, $payload);
			
			$value = $this->guardStore()->get($key, function() use ($writer, $key): string
			{
				$writer->delete($key);
				
				return 'computed';
			}, 60, queue: false);
			$read = $this->guardStore()->get($key, queue: false);
			$writer->delete($key);
			
			if($value !== 'computed' || $read !== null)
			{
				return false;
			}
		}
		
		return true;
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
	 * RULE (probe): a read that did not reach the item (the connection failed) is a miss, and no write
	 * follows it. The key may have been deleted or invalidated without this process seeing it, and with the
	 * queue off no second read checks it before the write. So the key is deleted instead.
	 */
	public function aWriteAfterAFailedReadDropsTheKey(): bool
	{
		$reader = $this->unreadProbe();
		$other = $this->guardStore();
		$key = $this->guardKey('unread');
		$other->set($key, 'old', 60);
		$reader->getConnection()->counter()->failingReads = 1;
		
		$value = $reader->get($key, function() use ($other, $key): string
		{
			// the source changes while the value is computed
			$other->delete($key);
			
			return 'computed before the delete';
		}, 60, queue: false);
		$read = $this->guardStore()->get($key, queue: false);
		$other->delete($key);
		
		return $value === 'computed before the delete'
			&& $read === null;
	}
	
	/**
	 * RULE (probe): a forced refresh whose read failed leaves no outdated value behind. The write could only
	 * be refused, so the key is deleted and the next reader computes.
	 */
	public function aForcedRefreshAfterAFailedReadLeavesNoOutdatedValue(): bool
	{
		$reader = $this->unreadProbe();
		$other = $this->guardStore();
		$key = $this->guardKey('refresh-unread');
		$other->set($key, 'outdated', 60);
		$reader->getConnection()->counter()->failingReads = 1;
		
		$value = $reader->get($key, fn(): string => 'refreshed', 60, refresh: true);
		$read = $this->guardStore()->get($key, queue: false);
		$other->delete($key);
		
		return $value === 'refreshed'
			&& $read === null;
	}
	
	/**
	 * RULE (probe): a set() after a read-only get() whose read failed leaves no outdated value behind: it
	 * answers false, and the key is deleted.
	 */
	public function aSetAfterAFailedReadLeavesNoOutdatedValue(): bool
	{
		$reader = $this->unreadProbe();
		$other = $this->guardStore();
		$key = $this->guardKey('set-unread');
		$other->set($key, 'old', 60);
		$reader->getConnection()->counter()->failingReads = 1;
		
		$missed = $reader->get($key, queue: false);
		$written = $reader->set($key, 'new', 60);
		$read = $this->guardStore()->get($key, queue: false);
		$other->delete($key);
		
		return $missed === null
			&& $written === false
			&& $read === null;
	}
	
	/**
	 * RULE (probe): a set() after a failed read of a key that holds no token is refused, and nothing is
	 * stored. A versioned store's guarded write takes a key without a token whatever token the miss
	 * names, so the failed read's marker is refused in PHP, before any call.
	 */
	public function aSetAfterAFailedReadOfAKeyWithoutATokenIsRefused(): bool
	{
		$reader = $this->unreadProbe();
		$key = $this->guardKey('unread-none');
		$id = $reader->itemId($key);
		$reader->getConnection()->counter()->failingReads = 1;
		
		$missed = $reader->get($key, queue: false);
		$written = $reader->set($key, 'new', 60);
		$stored = (int)$reader->getClient()->hExists($id, KeyValueRedis::KEY_DATA);
		$reader->delete($key);
		
		return $missed === null
			&& $written === false
			&& $stored === 0;
	}
	
	/**
	 * RULE (probe): when the write after a failed read deletes the key instead, it leaves no lock behind. A
	 * waiting worker computes at once; it does not wait for the lock's TTL.
	 */
	public function aDroppedWriteReleasesItsLock(): bool
	{
		$reader = $this->unreadProbe();
		$other = $this->guardStore();
		$key = $this->guardKey('drop-lock');
		$other->set($key, 'outdated', 60);
		$reader->getConnection()->counter()->failingReads = 1;
		
		$reader->get($key, fn(): string => 'refreshed', 60, refresh: true, queueLockTtlMs: 2000);
		$lockKey = $reader->getMemoLock()
			->getPrefixer()
			->prefix(MemoLock::TYPE_LOCK, $reader->itemId($key));
		$held = (int)$reader->getClient()->exists($lockKey);
		$began = microtime(true);
		$next = $this->guardStore()->get($key, fn(): string => 'next', 60);
		$tookMs = (microtime(true) - $began) * 1000;
		$reader->getClient()->del($lockKey);
		$other->delete($key);
		
		return $held === 0
			&& $next === 'next'
			&& $tookMs < 1000;
	}
	
	/**
	 * RULE (probe): a failed read whose write comes after the guard window. On a tag-index store it is a miss
	 * like any other: past the window a miss guards nothing, and the write is stored. On a versioned store
	 * the write is still refused, once (the store's own computation leaves the key), and the next write is
	 * stored.
	 */
	public function aWriteAfterAFailedReadPastTheWindow(): bool
	{
		$reader = $this->unreadProbe();
		$reader->setInvalidationWindowMs(50);
		$key = $this->guardKey('unread-late');
		$reader->getConnection()->counter()->failingReads = 1;
		
		$value = $reader->get($key, function(): string
		{
			usleep(100000);
			
			return 'late';
		}, 60, queue: false);
		$read = $this->guardStore()->get($key, queue: false);
		$written = $reader->set($key, 'next', 60);
		$next = $this->guardStore()->get($key, queue: false);
		$reader->delete($key);
		
		return $value === 'late'
			&& $read === ($reader instanceof RedisVersioned ? null : 'late')
			&& $written
			&& $next === 'next';
	}
	
	/**
	 * RULE (probe): a read answered by an error reply (BUSY, LOADING, MASTERDOWN answer false and throw
	 * nothing) is a failed read. A forced refresh after it leaves no outdated value behind.
	 */
	public function aForcedRefreshAfterAnErrorReplyLeavesNoOutdatedValue(): bool
	{
		$reader = $this->unreadProbe();
		$other = $this->guardStore();
		$key = $this->guardKey('refresh-erring');
		$other->set($key, 'outdated', 60);
		$reader->getConnection()->counter()->erringReads = 1;
		
		$value = $reader->get($key, fn(): string => 'refreshed', 60, refresh: true);
		$read = $this->guardStore()->get($key, queue: false);
		$other->delete($key);
		
		return $value === 'refreshed'
			&& $read === null;
	}
	
	/**
	 * RULE (probe): a second read made with the lock that gets an error reply is a failed read too, not a key
	 * found empty. The write after it is refused, and the key never holds the value computed.
	 */
	public function aLockLookAnsweredByAnErrorReplyIsAFailedRead(): bool
	{
		$reader = $this->unreadProbe();
		$key = $this->guardKey('look-erring');
		// the first read and the look made with the lock
		$reader->getConnection()->counter()->erringReads = 2;
		
		$value = $reader->get($key, fn(): string => 'computed', 60);
		$read = $this->guardStore()->get($key, queue: false);
		$reader->delete($key);
		
		return $value === 'computed'
			&& $read === null;
	}
	
	/**
	 * A fresh instance whose client fails reads when told (see batchProbe())
	 */
	protected function unreadProbe(): KeyValueRedis
	{
		$probe = $this->batchProbe();
		if($probe === null)
		{
			throw new SkipException('no single standalone client to fail a read on');
		}
		
		return $probe;
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
		// the tombstone expired by the server, at the end of its window
		$this->awaitServer(fn(): bool => (int)$client->exists($id) === 0);
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
	 * RULE: a deliberate set() after a read older than the window goes
	 * unguarded, on every store: the caller chose to write, and a fresh read
	 * first is the way to have it checked
	 */
	public function aDeliberateSetAfterAReadOlderThanTheWindowGoesUnguarded(): bool
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
	 * RULE: the store's own computation older than the window, with another
	 * process's tombstone still there at the write. A tag-index store guards
	 * the window only, so the write is stored. A versioned store compares the
	 * token at any age: the write is refused, and the next read does not get
	 * the value computed before the delete.
	 */
	public function anOwnComputationOlderThanTheWindowSeesALiveTombstone(): bool
	{
		// the writer's tombstone outlives the reader's window and its computation
		$reader = $this->guardStore(['invalidation_window_ms' => 100]);
		$writer = $this->guardStore(['invalidation_window_ms' => 10000]);
		$key = $this->guardKey('own-late');
		
		$value = $reader->get($key, function() use ($writer, $key): string
		{
			// the source changes while the value is computed
			$writer->delete($key);
			usleep(250000);
			
			return 'computed before the delete';
		}, 60);
		$read = $this->guardStore()->get($key, queue: false);
		$writer->delete($key);
		
		return $value === 'computed before the delete'
			&& $read === ($reader instanceof RedisVersioned ? null : 'computed before the delete');
	}
	
	/**
	 * RULE: a computation that outlasts the tombstone its read saw is stored,
	 * on every store: nothing was deleted or written since the read, the token
	 * only ran out
	 */
	public function aComputationThatOutlastsItsTombstoneIsStored(): bool
	{
		$reader = $this->guardStore(['invalidation_window_ms' => 100]);
		$writer = $this->guardStore(['invalidation_window_ms' => 100]);
		$client = $writer->getClient();
		$key = $this->guardKey('outlasted');
		$id = $writer->itemId($key);
		$writer->delete($key);
		
		$value = $reader->get($key, function() use ($client, $id): string
		{
			// the tombstone the read saw runs out, and the read grows older than its window
			$this->awaitServer(fn(): bool => (int)$client->exists($id) === 0);
			usleep(150000);
			
			return 'computed';
		}, 60);
		$read = $this->guardStore()->get($key, queue: false);
		$writer->delete($key);
		
		return $value === 'computed'
			&& $read === 'computed';
	}
	
	/**
	 * RULE: a read younger than the window whose token ran out before the write
	 * (a tombstone near its end). A versioned store takes the write: a delete
	 * or a write since the read would have left a token that lives a full
	 * window. A tag-index store compares the token as it is and refuses it.
	 */
	public function aYoungReadWhoseTokenRanOut(): bool
	{
		$writer = $this->guardStore(['invalidation_window_ms' => 400]);
		// the read stays younger than its window for the whole rule
		$reader = $this->guardStore(['invalidation_window_ms' => 10000]);
		$client = $writer->getClient();
		$key = $this->guardKey('young-ran-out');
		$id = $writer->itemId($key);
		$writer->delete($key);
		usleep(250000);
		// the read must still see the tombstone's token: a slow run that missed it proves nothing
		$tombstoned = (int)$client->exists($id) === 1;
		
		$value = $reader->get($key, function() use ($client, $id): string
		{
			$this->awaitServer(fn(): bool => (int)$client->exists($id) === 0);
			
			return 'computed';
		}, 60);
		$read = $this->guardStore()->get($key, queue: false);
		$writer->delete($key);
		
		return $tombstoned
			&& $value === 'computed'
			&& $read === ($reader instanceof RedisVersioned ? 'computed' : null);
	}
	
	/**
	 * RULE: a delete made during a computation is not seen once its tombstone
	 * ran out before the write, on every store. The guard sees a delete for the
	 * window after it, no longer: a computation longer than the window needs a
	 * longer window.
	 */
	public function aDeleteOlderThanTheWindowAtTheWriteIsNotSeen(): bool
	{
		$reader = $this->guardStore(['invalidation_window_ms' => 100]);
		$writer = $this->guardStore(['invalidation_window_ms' => 100]);
		$client = $writer->getClient();
		$key = $this->guardKey('bound');
		$id = $writer->itemId($key);
		// the read sees a token, so a check of the token as it is would refuse
		$writer->delete($key);
		
		$value = $reader->get($key, function() use ($writer, $key, $client, $id): string
		{
			$writer->delete($key);
			$this->awaitServer(fn(): bool => (int)$client->exists($id) === 0);
			usleep(150000);
			
			return 'computed before the delete';
		}, 60);
		$read = $this->guardStore()->get($key, queue: false);
		$writer->delete($key);
		
		return $value === 'computed before the delete'
			&& $read === 'computed before the delete';
	}
	
	/**
	 * RULE (versioned): a computation that outlasts the token of the outdated
	 * item its read found is stored once. Another reader removed the item, which
	 * keeps its token for the window only; the value computed after the tag
	 * invalidation is fresh, and the next read takes it without computing.
	 */
	public function aComputationAfterATagInvalidationThatOutlastsTheTokenIsStoredOnce(): bool
	{
		$writer = $this->versionedGuardStore(['invalidation_window_ms' => 200]);
		$reader = $this->guardStore(['invalidation_window_ms' => 200]);
		$client = $writer->getClient();
		$key = $this->guardKey('tag-outlasted');
		$id = $writer->itemId($key);
		// a write-through: the item carries a token for as long as it lives
		$writer->set($key, 'old', 60, [self::GUARD_TAG]);
		$writer->invalidateTags([self::GUARD_TAG]);
		$computed = 0;
		
		$value = $reader->get($key, function() use ($key, $client, $id, &$computed): string
		{
			$computed++;
			// another reader finds the item outdated and removes it: its token stays the window only
			$this->guardStore(['invalidation_window_ms' => 200])
				->get($key, queue: false);
			$this->awaitServer(fn(): bool => (int)$client->exists($id) === 0);
			
			return 'new';
		}, 60, [self::GUARD_TAG]);
		$next = $this->guardStore()->get($key, function() use (&$computed): string
		{
			$computed++;
			
			return 'computed again';
		}, 60, [self::GUARD_TAG]);
		$writer->delete($key);
		
		return $value === 'new'
			&& $next === 'new'
			&& $computed === 1;
	}
	
	/**
	 * RULE (versioned): a clear() during a computation whose token then ran
	 * out. The write is stored, with the rule id its read saw, so the next read
	 * takes it for outdated and misses.
	 */
	public function aClearDuringAComputationWhoseTokenRanOutLeavesAMiss(): bool
	{
		$writer = $this->versionedGuardStore(['invalidation_window_ms' => 100]);
		$reader = $this->guardStore(['invalidation_window_ms' => 100]);
		$client = $writer->getClient();
		$key = $this->guardKey('clear-outlasted');
		$id = $writer->itemId($key);
		// the read sees this tombstone's token
		$writer->delete($key);
		
		$value = $reader->get($key, function() use ($writer, $client, $id): string
		{
			$writer->clear();
			$this->awaitServer(fn(): bool => (int)$client->exists($id) === 0);
			
			return 'computed before the clear';
		}, 60);
		$stored = (int)$client->hExists($id, KeyValueRedis::KEY_DATA) === 1;
		$read = $this->guardStore()->get($key, queue: false);
		$writer->delete($key);
		
		return $value === 'computed before the clear'
			&& $stored
			&& $read === null;
	}
	
	/**
	 * RULE (versioned): somebody's plain set() during the store's own
	 * computation, its token still there at the write, the read older than the
	 * window: the computation is refused, and the set() value stays
	 */
	public function aPlainSetDuringAnOldComputationRefusesIt(): bool
	{
		$reader = $this->versionedGuardStore(['invalidation_window_ms' => 100]);
		$writer = $this->guardStore();
		$key = $this->guardKey('set-outlasted');
		
		$value = $reader->get($key, function() use ($writer, $key): string
		{
			$writer->set($key, 'new', 60);
			usleep(250000);
			
			return 'computed before the set';
		}, 60);
		$read = $this->guardStore()->get($key, queue: false);
		$writer->delete($key);
		
		return $value === 'computed before the set'
			&& $read === 'new';
	}
	
	/**
	 * RULE (versioned): after a read older than the window, with another
	 * process's token alive on the key, a deliberate set() is written while the
	 * store's own computation is refused
	 */
	public function anOldReadWritesADeliberateSetAndRefusesTheOwnComputation(): bool
	{
		$reader = $this->versionedGuardStore(['invalidation_window_ms' => 100]);
		$writer = $this->guardStore();
		$deliberate = $this->guardKey('deliberate-old');
		$own = $this->guardKey('own-old');
		
		// a read-only get(), somebody's set(), then the caller's own set()
		$reader->get($deliberate, queue: false);
		$writer->set($deliberate, 'other', 60);
		usleep(250000);
		$written = $reader->set($deliberate, 'mine', 60);
		$readDeliberate = $this->guardStore()->get($deliberate, queue: false);
		
		// the same order, the value computed by the store
		$computed = $reader->get($own, function() use ($writer, $own): string
		{
			$writer->set($own, 'other', 60);
			usleep(250000);
			
			return 'mine';
		}, 60);
		$readOwn = $this->guardStore()->get($own, queue: false);
		$writer->delete($deliberate);
		$writer->delete($own);
		
		return $written
			&& $readDeliberate === 'mine'
			&& $computed === 'mine'
			&& $readOwn === 'other';
	}
	
	/**
	 * RULE (versioned): a plain set() with a TTL shorter than the window, onto a
	 * key with little life left (a tombstone), keeps its new token a full
	 * window. A computation whose read came before that set() is refused after
	 * the short value expired.
	 */
	public function aShortLivedSetKeepsItsTokenAFullWindow(): bool
	{
		$deleter = $this->versionedGuardStore(['invalidation_window_ms' => 300]);
		$reader = $this->guardStore(['invalidation_window_ms' => 10000]);
		$writer = $this->guardStore(['invalidation_window_ms' => 10000]);
		$client = $writer->getClient();
		$key = $this->guardKey('short-set');
		$id = $writer->itemId($key);
		// a tombstone that lives 300 ms
		$deleter->delete($key);
		$kept = 0;
		
		$value = $reader->get($key, function() use ($writer, $key, $client, $id, &$kept): string
		{
			$writer->set($key, 'short', 1);
			$kept = (int)$client->pTtl($id);
			$this->awaitServer(fn(): bool => $this->dataExpired($writer, [$key]));
			
			return 'computed before the set';
		}, 60);
		$read = $this->guardStore()->get($key, queue: false);
		$writer->delete($key);
		
		return $value === 'computed before the set'
			&& $kept > 1000
			&& $read === null;
	}
	
	/**
	 * RULE (versioned): a forced refresh is the store's own computation. Past the window it still sees a
	 * delete made within the window before its write: refused, and the next read misses.
	 */
	public function aForcedRefreshPastTheWindowSeesADelete(): bool
	{
		$reader = $this->versionedGuardStore(['invalidation_window_ms' => 100]);
		// the tombstone outlives the refresh
		$writer = $this->guardStore(['invalidation_window_ms' => 10000]);
		$key = $this->guardKey('forced-late');
		$writer->set($key, 'old', 60);
		
		$value = $reader->get($key, function() use ($writer, $key): string
		{
			$writer->delete($key);
			usleep(250000);
			
			return 'refreshed before the delete';
		}, 60, refresh: true);
		$read = $this->guardStore()->get($key, queue: false);
		$writer->delete($key);
		
		return $value === 'refreshed before the delete'
			&& $read === null;
	}
	
	/**
	 * RULE (versioned): a forced refresh past the window whose token ran out meanwhile (the item it
	 * replaces expired, its token with it) is stored
	 */
	public function aForcedRefreshPastTheWindowWhoseTokenRanOutIsStored(): bool
	{
		$reader = $this->versionedGuardStore(['invalidation_window_ms' => 100]);
		$writer = $this->guardStore(['invalidation_window_ms' => 100]);
		$client = $writer->getClient();
		$key = $this->guardKey('forced-ran-out');
		$id = $writer->itemId($key);
		// written through, so the item carries a token, which expires with it in a second
		$writer->set($key, 'old', 1);
		
		$value = $reader->get($key, function() use ($client, $id): string
		{
			$this->awaitServer(fn(): bool => (int)$client->exists($id) === 0);
			
			return 'refreshed';
		}, 60, refresh: true);
		$read = $this->guardStore()->get($key, queue: false);
		$writer->delete($key);
		
		return $value === 'refreshed'
			&& $read === 'refreshed';
	}
	
	/**
	 * RULE (versioned): a stale value's refresh is the store's own computation, alone (get()) and in a
	 * batch (getMany()). Past the window it still sees another process's write made within the window
	 * before its own: refused, and that write's value stays.
	 */
	public function aStaleRefreshPastTheWindowSeesAWrite(): bool
	{
		$reader = $this->versionedGuardStore(['invalidation_window_ms' => 100]);
		$writer = $this->guardStore();
		$alone = $this->guardKey('stale-late');
		$batched = $this->guardKey('stale-late-batch');
		$writer->set($alone, new Stale('old', microtime(true) - 1, 60), 60);
		$writer->set($batched, new Stale('old', microtime(true) - 1, 60), 60);
		
		$reader->get($alone, function() use ($writer, $alone): string
		{
			$writer->set($alone, 'other', 60);
			usleep(250000);
			
			return 'refreshed before the write';
		}, 60, stale: 60);
		$reader->getMany([$batched], function(KeyValueRedis $store, array $keys) use ($writer, $batched): array
		{
			$writer->set($batched, 'other', 60);
			usleep(250000);
			
			return [$batched => 'refreshed before the write'];
		}, 60, stale: 60);
		$readAlone = $this->guardStore()->get($alone, queue: false);
		$readBatched = $this->guardStore()->get($batched, queue: false);
		$writer->delete($alone);
		$writer->delete($batched);
		
		return $readAlone === 'other'
			&& $readBatched === 'other';
	}
	
	/**
	 * RULE (versioned): a stale value's refresh past the window whose token ran out meanwhile (the stale
	 * item expired, its token with it) is stored
	 */
	public function aStaleRefreshPastTheWindowWhoseTokenRanOutIsStored(): bool
	{
		$reader = $this->versionedGuardStore(['invalidation_window_ms' => 100]);
		$writer = $this->guardStore(['invalidation_window_ms' => 100]);
		$client = $writer->getClient();
		$key = $this->guardKey('stale-ran-out');
		$id = $writer->itemId($key);
		// written through, so the item carries a token, which expires with it in a second
		$writer->set($key, new Stale('old', microtime(true) - 1, 60), 1);
		
		$reader->get($key, function() use ($client, $id): string
		{
			$this->awaitServer(fn(): bool => (int)$client->exists($id) === 0);
			
			return 'refreshed';
		}, 60, stale: 60);
		$read = $this->guardStore()->get($key, queue: false);
		$writer->delete($key);
		
		return $read === 'refreshed';
	}
	
	/**
	 * RULE (versioned): the page cache's refresh (peek(), then startComputing()) is the store's own
	 * computation. Past the window it still sees a delete made within the window before its write:
	 * refused, and the next read misses.
	 */
	public function aPageRefreshPastTheWindowSeesADelete(): bool
	{
		$reader = $this->versionedGuardStore(['invalidation_window_ms' => 100]);
		// the tombstone outlives the refresh
		$writer = $this->guardStore(['invalidation_window_ms' => 10000]);
		$key = $this->guardKey('page-late');
		$writer->set($key, new Stale('old page', microtime(true) - 1, 60), 60);
		
		$reader->peek($key);
		$reader->startComputing($key);
		$writer->delete($key);
		usleep(250000);
		$written = $reader->set($key, 'new page', 60);
		$read = $this->guardStore()->get($key, queue: false);
		$writer->delete($key);
		
		return $written === false
			&& $read === null;
	}
	
	/**
	 * RULE (versioned): the page cache's refresh past the window whose token ran out meanwhile (the page it
	 * replaces expired, its token with it) is stored
	 */
	public function aPageRefreshPastTheWindowWhoseTokenRanOutIsStored(): bool
	{
		$reader = $this->versionedGuardStore(['invalidation_window_ms' => 100]);
		$writer = $this->guardStore(['invalidation_window_ms' => 100]);
		$client = $writer->getClient();
		$key = $this->guardKey('page-ran-out');
		$id = $writer->itemId($key);
		// written through, so the page carries a token, which expires with it in a second
		$writer->set($key, new Stale('old page', microtime(true) - 1, 60), 1);
		
		$reader->peek($key);
		$reader->startComputing($key);
		$this->awaitServer(fn(): bool => (int)$client->exists($id) === 0);
		$written = $reader->set($key, 'new page', 60);
		$read = $this->guardStore()->get($key, queue: false);
		$writer->delete($key);
		
		return $written
			&& $read === 'new page';
	}
	
	/**
	 * RULE (versioned): a manual lockAndQueue() whose resolver throws leaves nothing behind that makes a
	 * later write the store's own. The caller reads again (peek()); its deliberate set() after that read,
	 * older than the window, is written over another process's newer value.
	 */
	public function aThrownComputationLeavesADeliberateSetUnguarded(): bool
	{
		$reader = $this->versionedGuardStore(['invalidation_window_ms' => 100]);
		$writer = $this->guardStore();
		$key = $this->guardKey('thrown');
		
		try
		{
			$reader->lockAndQueue($key, function(): never
			{
				throw new RuntimeException('the computation failed');
			});
		}
		catch(RuntimeException)
		{
			// the caller goes on
		}
		// a read that computes nothing: only the read itself can drop what the failed computation left
		$reader->peek($key);
		$writer->set($key, 'other', 60);
		usleep(250000);
		$written = $reader->set($key, 'mine', 60);
		$read = $this->guardStore()->get($key, queue: false);
		$writer->delete($key);
		
		return $written
			&& $read === 'mine';
	}
	
	/**
	 * A fresh versioned store - another process; skips a rule on a tag-index
	 * store
	 */
	protected function versionedGuardStore(
		array $storeOptions = [],
	): RedisVersioned
	{
		$store = $this->guardStore($storeOptions);
		if($store instanceof RedisVersioned === false)
		{
			throw new SkipException('only the versioned stores compare the token of a computation at any age');
		}
		
		return $store;
	}
	
	/**
	 * RULE: a versioned recomputation that outlives the window keeps the
	 * rules stamp its miss took - an invalidation made while it computed
	 * reaches its write (the epoch is the window's, the stamp holds for the
	 * rules' retention)
	 */
	public function aRecomputationPastTheWindowSeesTheRulesItMissed(): bool
	{
		$reader = $this->guardStore(['invalidation_window_ms' => 100]);
		if($reader instanceof RedisVersioned === false)
		{
			throw new SkipException('only the versioned stores stamp a miss with their rules');
		}
		$writer = $this->guardStore();
		$writer->delete(self::GUARD_KEY);
		
		$computed = $reader->get(self::GUARD_KEY, function() use ($writer): string
		{
			$writer->invalidateTags([self::GUARD_TAG]);
			usleep(250000);
			
			return 'computed before the invalidation';
		}, 60, [self::GUARD_TAG]);
		$read = $writer->get(self::GUARD_KEY, queue: false);
		$writer->delete(self::GUARD_KEY);
		
		return $computed === 'computed before the invalidation'
			&& $read === null;
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
	 * RULE: a tag invalidation while a locked miss computes - get() with a
	 * resolver, the stampede lock taken - refuses its write, or reads it stale
	 */
	public function aTagInvalidationWhileALockedMissComputesIsNeverServed(): bool
	{
		$reader = $this->guardStore();
		$writer = $this->guardStore();
		$key = $this->guardKey('locked');
		
		$value = $reader->get($key, function() use ($writer): string
		{
			$writer->invalidateTags([self::GUARD_TAG]);
			
			return 'computed before the invalidation';
		}, 60, [self::GUARD_TAG]);
		$read = $this->guardStore()->get($key, queue: false);
		$writer->delete($key);
		
		return $value === 'computed before the invalidation'
			&& $read === null;
	}
	
	/**
	 * RULE: a clear while a locked miss computes refuses its write too - for a
	 * key no process wrote, which no tombstone marks (the store's "cleared"
	 * stamp refuses it, sent with the write and the lock's release)
	 */
	public function aClearWhileALockedMissComputesIsNeverServed(): bool
	{
		$reader = $this->guardStore();
		$writer = $this->guardStore();
		$key = $this->guardKey('locked-clear');
		
		$value = $reader->get($key, function() use ($writer): string
		{
			$writer->clear();
			
			return 'computed before the clear';
		}, 60);
		$read = $this->guardStore()->get($key, queue: false);
		$writer->delete($key);
		
		return $value === 'computed before the clear'
			&& $read === null;
	}
	
	/**
	 * RULE: a miss taken by hand - lockAndQueue(), the caller computes and
	 * sets - is stamped like get()'s: a tag invalidation while it computes
	 * refuses its write, or reads it stale (the key's own mark cannot show
	 * it: the invalidation reaches no item, the key holds none)
	 */
	public function aManuallyLockedWriteAfterATagInvalidationIsNeverServed(): bool
	{
		$reader = $this->guardStore();
		$writer = $this->guardStore();
		$key = $this->guardKey('manual');
		$writer->delete($key);
		
		$locked = $reader->lockAndQueue($key) === null;
		$writer->invalidateTags([self::GUARD_TAG]);
		$reader->set($key, 'stale', 60, [self::GUARD_TAG]);
		$read = $this->guardStore()->get($key, queue: false);
		$writer->delete($key);
		
		return $locked && $read === null;
	}
	
	/**
	 * RULE: a value computed before another process cleared the store is
	 * never served after it - also for a key no process ever wrote, which
	 * no tombstone marks (the wipe's own stamp refuses it)
	 */
	public function aWriteAfterAClearIsNeverServed(): bool
	{
		$reader = $this->guardStore();
		$writer = $this->guardStore();
		$writer->delete(self::GUARD_KEY);
		$never = $this->guardKey('cleared');
		
		$reader->get(self::GUARD_KEY, queue: false);
		$reader->get($never, queue: false);
		$writer->clear();
		$reader->set(self::GUARD_KEY, 'stale', 60);
		$reader->set($never, 'stale', 60);
		$read = $this->guardStore()->get(self::GUARD_KEY, queue: false);
		$readNever = $this->guardStore()->get($never, queue: false);
		$writer->delete(self::GUARD_KEY);
		$writer->delete($never);
		
		return $read === null
			&& $readNever === null;
	}
}
