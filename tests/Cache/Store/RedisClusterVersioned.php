<?php
declare(strict_types=1);

namespace Tests\Cache\Store;

use Ovos\Cache\MemoLock\Redis as MemoLock;
use Ovos\Cache\Redis\Functions;
use Ovos\Cache\Store\RedisClusterVersioned as Store;
use Ovos\Cache\Store\KeyValue\Redis as KeyValueRedis;
use Ovos\Test;
use Ovos\Test\Internal;
use Ovos\Test\Cache\Store\TraitInvalidationGuard;
use Ovos\Test\Cache\Store\TraitPrune;
use Ovos\Test\Cache\Store\TraitStaleWhileRevalidate;
use Ovos\Test\Cache\Store\TraitBatch;
use Ovos\Test\Cache\Store\TraitCachePolicy;
use Ovos\Test\Cache\Store\TraitStoredStrings;
use Ovos\Test\Cache\Store\TraitRedisCluster;
use Ovos\Test\Exception\SkipException;
use Override;
use Closure;
use Redis as RedisClient;
use RedisCluster as RedisClusterClient;
use RedisClusterException;
use RedisException;
use ReflectionProperty;
use RuntimeException;

use function Ovos\config;

use function apcu_delete;
use function apcu_fetch;
use function apcu_store;
use function array_diff;
use function array_key_last;
use function array_keys;
use function array_map;
use function array_search;
use function array_unique;
use function array_values;
use function bin2hex;
use function count;
use function explode;
use function gc_collect_cycles;
use function in_array;
use function is_array;
use function is_string;
use function ksort;
use function max;
use function microtime;
use function preg_match;
use function random_bytes;
use function reset;
use function str_contains;
use function trim;
use function usleep;

/**
 * RedisClusterVersioned
 *
 * Mirrors the RedisVersioned suite against a real Redis Cluster, plus a
 * cross-node invalidation test. Requires the "redis_cluster" and
 * "redis_cluster_queue" connections in the environment config, skipped
 * when missing or unreachable.
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class RedisClusterVersioned extends Test
{
	use TraitRedisCluster;
	use TraitInvalidationGuard;
	use TraitPrune;
	use TraitStaleWhileRevalidate;
	use TraitStoredStrings;
	use TraitCachePolicy;
	use TraitBatch;
	
	public const string KEY_ITEM = 'item';
	
	protected ?Store $store = null;
	
	public function __construct()
	{
		$this->store = $this->getClusterStore();
		
		if($this->store === null)
		{
			$this->setDisabled(true, $this->clusterUnavailableReason);
		}
	}
	
	public function delete(): bool
	{
		$this->store->set(self::KEY_ITEM, 'test');
		$this->store->delete(self::KEY_ITEM);
		
		$exists = $this->store->get(self::KEY_ITEM, queue: false);
		
		return $exists === null;
	}
	
	public function storeArray(): bool
	{
		$array = [
			'stored' => true
		];
		
		$this->store->set(self::KEY_ITEM, $array);
		$array = $this->store->get(self::KEY_ITEM, queue: false);
		
		try
		{
			return $array['stored'] === true;
		}
		finally
		{
			$this->store->delete(self::KEY_ITEM);
		}
	}
	
	public function invalidateTags(): bool
	{
		$tags = ['tag1', 'tag2'];
		
		$this->store->set(self::KEY_ITEM, 'test', tags: $tags);
		$this->store->invalidateTags([$tags[0]]);
		
		$result = $this->store->get(self::KEY_ITEM, queue: false);
		
		try
		{
			return $result === null;
		}
		finally
		{
			$this->store->delete(self::KEY_ITEM);
		}
	}
	
	public function invalidateTagsMatchingAll(): bool
	{
		$this->store->set('item1', 'test', tags: ['tag1', 'tag2']);
		$this->store->set('item2', 'test', tags: ['tag1']);
		
		$this->store->invalidateTags(['tag1', 'tag2'], Store::MATCHING_ALL);
		
		$item1 = $this->store->get('item1', queue: false);
		$item2 = $this->store->get('item2', queue: false);
		
		try
		{
			// only the item having all the tags is invalidated
			return $item1 === null
				&& $item2 === 'test';
		}
		finally
		{
			$this->store->delete('item1');
			$this->store->delete('item2');
		}
	}
	
	/**
	 * An item written after the invalidation must survive it - the item
	 * and the rules timestamps come from different cluster nodes here,
	 * which also exercises the cross-node clock assumption
	 */
	public function invalidateThenSet(): bool
	{
		$tags = ['tag1'];
		
		$this->store->invalidateTags($tags);
		$this->store->set(self::KEY_ITEM, 'test', tags: $tags);
		
		$result = $this->store->get(self::KEY_ITEM, queue: false);
		
		try
		{
			return $result === 'test';
		}
		finally
		{
			$this->store->delete(self::KEY_ITEM);
		}
	}
	
	/**
	 * Enough items that their keys hash to slots on every master node:
	 * one O(1) rule must invalidate them all, wherever they live
	 */
	public function invalidateTagsAcrossNodes(): bool
	{
		for($i = 1; $i <= 30; $i++)
		{
			$this->store->set('item' . $i, 'test', tags: ['spread']);
		}
		
		$this->store->invalidateTags(['spread']);
		
		$remaining = 0;
		for($i = 1; $i <= 30; $i++)
		{
			if($this->store->get('item' . $i, queue: false) !== null)
			{
				$remaining++;
			}
		}
		
		try
		{
			return $remaining === 0;
		}
		finally
		{
			for($i = 1; $i <= 30; $i++)
			{
				$this->store->delete('item' . $i);
			}
		}
	}
	
	public function clear(): bool
	{
		$array = [
			'stored' => true
		];
		
		$this->store->set(self::KEY_ITEM, $array);
		$this->store->clear();
		$result = $this->store->get(self::KEY_ITEM, queue: false);
		
		return $result === null;
	}
	
	/**
	 * The rules stream is the only record that an invalidation happened: it
	 * must carry no TTL, or a volatile-* eviction policy could pick it
	 */
	public function rulesKeyIsNotVolatile(): bool
	{
		$client = $this->store->getClient();
		$rulesKey = $this->store->getRulesKey();
		
		$this->store->invalidateTags(['tag1']);
		
		if($client->pttl($rulesKey) !== -1)
		{
			return false;
		}
		
		// a stream left behind by an earlier version, which gave it a TTL
		$client->pexpire($rulesKey, 60000);
		$this->store->invalidateTags(['tag1']);
		
		return $client->pttl($rulesKey) === -1;
	}
	
	/**
	 * An invalidated item must not come back when the rules stream is lost
	 * (evicted, deleted, gone with its slot) before anyone read the item -
	 * here the watermark the item carries came from the cached rule set
	 */
	public function lostRulesDoNotResurrectAnItem(): bool
	{
		$tags = ['tag1'];
		
		// a rule before the item, so the item carries a real watermark
		$this->store->invalidateTags(['earlier']);
		$this->store->set(self::KEY_ITEM, 'test', tags: $tags);
		$this->store->invalidateTags($tags);
		
		$this->store->getClient()
			->del($this->store->getRulesKey());
		
		$result = $this->store->get(self::KEY_ITEM, queue: false);
		
		try
		{
			return $result === null;
		}
		finally
		{
			$this->store->delete(self::KEY_ITEM);
		}
	}
	
	/**
	 * The same, when another invalidation rebuilt the stream in between: the
	 * head is fresh again, and the rule that staled the item is nowhere in it
	 */
	public function lostRulesDoNotResurrectAnItemAfterAnotherRule(): bool
	{
		$tags = ['tag1'];
		$client = $this->store->getClient();
		$rules = $this->store->getRulesKey();
		
		// The stream is opened by hand, on an id from 1970, and the item stamps
		// on THAT: a stream reborn from the server clock is then newer than the
		// stamp by construction. Waiting for the clock to move instead is a race
		// this test lost on every CI runner - stream ids are milliseconds, and a
		// host that writes the item, invalidates, drops the stream and rebuilds
		// it inside one of them reopens on the id the item already carries,
		// which rulesLostSince() reads (correctly, see its own note) as nothing
		// lost. The rule's fields are the shape cache_versioned_invalidate
		// writes, and `first` says this one opened the stream.
		$client->xAdd($rules, '1-0', ['mode' => 'any', 'tags' => 'seed', 'first' => '1']);
		
		$this->store->set(self::KEY_ITEM, 'test', tags: $tags);
		$this->store->invalidateTags($tags);
		
		$client->del($rules);
		
		// and this one opens it again, at the server clock: decades newer than
		// the stamp, whatever the runner's speed
		$this->store->invalidateTags(['unrelated']);
		
		$result = $this->store->get(self::KEY_ITEM, queue: false);
		
		try
		{
			return $result === null;
		}
		finally
		{
			$this->store->delete(self::KEY_ITEM);
		}
	}
	
	/**
	 * An item written after the loss has seen no rule: the rebuilt stream
	 * must not be held against it
	 */
	public function itemWrittenAfterLostRulesIsServed(): bool
	{
		$this->store->invalidateTags(['earlier']);
		$this->store->getClient()
			->del($this->store->getRulesKey());
		
		$this->store->set(self::KEY_ITEM, 'test', tags: ['tag1']);
		$this->store->invalidateTags(['unrelated']);
		
		$result = $this->store->get(self::KEY_ITEM, queue: false);
		
		try
		{
			return $result === 'test';
		}
		finally
		{
			$this->store->delete(self::KEY_ITEM);
		}
	}
	
	/**
	 * A failover to a replica that never got the newest rule, where another server's invalidation reached the
	 * new master before this copy was refreshed: the stream still holds every older rule, so the copy is read
	 * again from the start, and an item one of them outdated stays a miss (rebuilt from the answer alone, the
	 * copy dropped the older rules and served it as fresh)
	 */
	public function aFailoverThatLosesTheNewestRuleKeepsTheOlderRules(): bool
	{
		$store = $this->getClusterStore(['rules_cache_ms' => 0, 'rules_shared_cache' => false]);
		$client = $store->getClient();
		$rulesKey = $store->getRulesKey();
		$key = 'failover_' . bin2hex(random_bytes(4));
		$tag = 'failover-' . bin2hex(random_bytes(4));
		
		try
		{
			// a rule before the item, so the item carries a real stamp; then the rule that outdates it, and the
			// newest rule, which this copy holds
			$store->invalidateTags([$tag . '-earlier']);
			$store->set($key, 'outdated', 60, [$tag]);
			$store->invalidateTags([$tag]);
			$store->invalidateTags([$tag . '-newest']);
			$newest = (string)array_key_last($client->xRange($rulesKey, '-', '+'));
			// the copy must hold the newest rule, or the read below would be a miss through plain
			// continuity and test nothing
			$heldNewest = (new ReflectionProperty($store, 'rules'))->getValue($store)?->last() === $newest;
			
			// the failover: the new master never got the newest rule, and another server's invalidation
			// reached it first (written by hand, so this copy does not take it in)
			$client->xDel($rulesKey, [$newest]);
			$client->xAdd($rulesKey, '*', ['mode' => 'any', 'tags' => $tag . '-after', 'first' => '0']);
			
			return $heldNewest
				&& $store->get($key, queue: false) === null;
		}
		finally
		{
			$store->delete($key);
		}
	}
	
	public function clearPhysical(): bool
	{
		$this->store->set(self::KEY_ITEM, 'test');
		$this->store->clearPhysical();
		
		// physically gone, not just logically stale
		$id = $this->store->prefix(self::KEY_ITEM, $this->store->getType());
		$exists = $this->store->getClient()
			->exists($id);
		
		return $exists === 0;
	}
	
	/**
	 * RULE: after a slot was handed over to another master, a client that still holds the old slot map writes
	 * a key of that slot at once (a guarded write, safe to run twice), and another one deletes one at once and
	 * leaves its tombstone. The Lua call that met MOVED is sent again after one typed read of the key
	 * refreshed the map (see Functions::sendByKey())
	 */
	public function aSlotHandedOverIsWrittenAndDeletedAtOnce(): bool
	{
		$writer = $this->movingStore();
		$deleter = $this->movingStore();
		$masters = $this->masters($writer);
		$key = $this->keyAwayFromTheRules($writer, $masters, 'handed-over');
		$id = $writer->itemId($key);
		$slot = $this->slotOf($masters, $id);
		$from = $this->ownerOf($masters, $slot);
		$to = $this->otherMaster($masters, $from);
		// the writer's read misses: its write after it is guarded
		$writer->get($key, queue: false);
		
		try
		{
			$this->handOver($masters, $slot, $from, $to);
			$written = $writer->set($key, 'new', 60);
			$reader = $this->movingStore();
			$read = $reader->get($key, queue: false);
			$deleted = $deleter->delete($key);
			$fields = array_keys((array)$writer->getClient()->hGetAll($id));
			$gone = $reader->get($key, queue: false);
		}
		finally
		{
			// the keys go and the connections close also when moving back threw
			try
			{
				$this->moveBack($masters, $slot, $to, $from);
			}
			finally
			{
				$this->leaveMoved($masters, [$writer, $deleter, $reader ?? null], [$id]);
			}
		}
		
		return $written
			&& $read === 'new'
			&& $deleted
			&& $fields === [KeyValueRedis::KEY_EPOCH]
			&& $gone === null;
	}
	
	/**
	 * RULE: after a slot was handed over, a plain write (a stamped write, not safe to run twice) of a client
	 * that still holds the old slot map fails once and is not sent again: its typed read only brings the map
	 * up to date, and the next write lands
	 */
	public function aPlainWriteAfterAHandOverFailsOnceAndTheNextLands(): bool
	{
		$store = $this->movingStore();
		$masters = $this->masters($store);
		$key = $this->keyAwayFromTheRules($store, $masters, 'handed-over-plain');
		$id = $store->itemId($key);
		$slot = $this->slotOf($masters, $id);
		$from = $this->ownerOf($masters, $slot);
		$to = $this->otherMaster($masters, $from);
		$store->set($key, 'old', 60);
		
		try
		{
			$this->handOver($masters, $slot, $from, $to);
			// a store of an earlier rule destroyed meanwhile would send calls of its own (a stale drop)
			gc_collect_cycles();
			$before = $this->commandCalls($masters[$to][0], 'fcall');
			$first = $store->set($key, 'new', 60);
			$sent = $this->commandCalls($masters[$to][0], 'fcall') - $before;
			$next = $store->set($key, 'next', 60);
			$reader = $this->movingStore();
			$read = $reader->get($key, queue: false);
		}
		finally
		{
			// the keys go and the connections close also when moving back threw
			try
			{
				$this->moveBack($masters, $slot, $to, $from);
			}
			finally
			{
				$this->leaveMoved($masters, [$store, $reader ?? null], [$id]);
			}
		}
		
		return $first === false
			&& $sent === 0
			&& $next
			&& $read === 'next';
	}
	
	/**
	 * RULE: after the slot of the rules stream was handed over to another master, a client that still holds
	 * the old slot map adds its invalidation at once, and the item it reaches is a miss
	 */
	public function aRulesSlotHandedOverTakesAnInvalidationAtOnce(): bool
	{
		$store = $this->movingStore();
		$masters = $this->masters($store);
		$key = 'handed-over-rules:' . bin2hex(random_bytes(4));
		$tag = 'handed-over-' . bin2hex(random_bytes(4));
		$rules = $store->getRulesKey();
		$store->set($key, 'old', 60, [$tag]);
		$slot = $this->slotOf($masters, $rules);
		$from = $this->ownerOf($masters, $slot);
		$to = $this->otherMaster($masters, $from);
		
		try
		{
			$this->handOver($masters, $slot, $from, $to);
			$invalidated = $store->invalidateTags([$tag]);
			$reader = $this->movingStore();
			$read = $reader->get($key, queue: false);
		}
		finally
		{
			// the keys go and the connections close also when moving back threw
			try
			{
				$this->moveBack($masters, $slot, $to, $from);
			}
			finally
			{
				$this->leaveMoved($masters, [$store, $reader ?? null], [$store->itemId($key)]);
			}
		}
		
		return $invalidated
			&& $read === null;
	}
	
	/**
	 * RULE: while a slot migrates, the delete of a key that already went to the new master leaves its
	 * tombstone there: the Lua call follows no ASK, so typed calls write the same tombstone (see
	 * KeyValue\Redis::typedTombstone()). The item is not served
	 */
	public function aDeleteDuringAMigrationLeavesATombstone(): bool
	{
		$store = $this->movingStore();
		$masters = $this->masters($store);
		$key = 'migrating-delete:' . bin2hex(random_bytes(4));
		$id = $store->itemId($key);
		$slot = $this->slotOf($masters, $id);
		$from = $this->ownerOf($masters, $slot);
		$to = $this->otherMaster($masters, $from);
		$store->set($key, 'old', 60);
		
		try
		{
			$this->startMigration($masters, $slot, $from, $to, [$id]);
			$deleted = $store->delete($key);
			$reader = $this->movingStore();
			$read = $reader->get($key, queue: false);
			$fields = $this->fieldsWhileImporting($masters[$to][0], $id);
		}
		finally
		{
			// the keys go and the connections close also when moving back threw
			try
			{
				$this->finishAndMoveBack($masters, $slot, $from, $to);
			}
			finally
			{
				$this->leaveMoved($masters, [$store, $reader ?? null], [$id]);
			}
		}
		
		return $deleted
			&& $read === null
			&& $fields === [KeyValueRedis::KEY_EPOCH];
	}
	
	/**
	 * RULE: a write whose read came before a delete made during a migration is refused once the slot is
	 * handed over: the tombstone the delete wrote by typed calls carries a new token, as the Lua one does
	 */
	public function aWriteReadBeforeADeleteDuringAMigrationIsRefusedAfterTheHandOver(): bool
	{
		$writer = $this->movingStore();
		$reader = $this->movingStore();
		$deleter = $this->movingStore();
		$masters = $this->masters($writer);
		$key = 'migrating-guard:' . bin2hex(random_bytes(4));
		$id = $writer->itemId($key);
		$slot = $this->slotOf($masters, $id);
		$from = $this->ownerOf($masters, $slot);
		$to = $this->otherMaster($masters, $from);
		// the key holds a tombstone, and the reader's miss saw its token
		$writer->set($key, 'old', 60);
		$writer->delete($key);
		$reader->get($key, queue: false);
		
		try
		{
			$this->startMigration($masters, $slot, $from, $to, [$id]);
			$deleter->delete($key);
			$this->handOver($masters, $slot, $from, $to);
			$written = $reader->set($key, 'computed before the delete', 60);
			$fresh = $this->movingStore();
			$read = $fresh->get($key, queue: false);
		}
		finally
		{
			// the keys go and the connections close also when moving back threw
			try
			{
				$this->moveBack($masters, $slot, $to, $from);
			}
			finally
			{
				$this->leaveMoved($masters, [$writer, $reader, $deleter, $fresh ?? null], [$id]);
			}
		}
		
		return $written === false
			&& $read === null;
	}
	
	/**
	 * RULE: while a slot migrates, the write of a key the old master does not hold - moved already, or not
	 * there yet - answers false: its Lua call follows no ASK, and a typed read changes no slot map. So does a
	 * guarded write, sent twice. Nothing is written, the value there stays
	 */
	public function aSetDuringAMigrationAnswersFalse(): bool
	{
		$store = $this->movingStore();
		$masters = $this->masters($store);
		// one slot for both: a hash tag
		$tag = '{migrating-set-' . bin2hex(random_bytes(4)) . '}';
		$moved = $tag . ':moved';
		$absent = $tag . ':absent';
		$guarded = $tag . ':guarded';
		$ids = [$store->itemId($moved), $store->itemId($absent), $store->itemId($guarded)];
		$slot = $this->slotOf($masters, $ids[0]);
		$from = $this->ownerOf($masters, $slot);
		$to = $this->otherMaster($masters, $from);
		$store->set($moved, 'old', 60);
		// a miss: the write after it is guarded
		$store->get($guarded, queue: false);
		
		try
		{
			$this->startMigration($masters, $slot, $from, $to, [$ids[0]]);
			$written = [
				$store->set($moved, 'new', 60),
				$store->set($absent, 'new', 60),
				$store->set($guarded, 'new', 60),
			];
			$reader = $this->movingStore();
			$read = [
				$reader->get($moved, queue: false),
				$reader->get($absent, queue: false),
				$reader->get($guarded, queue: false),
			];
		}
		finally
		{
			// the keys go and the connections close also when moving back threw
			try
			{
				$this->finishAndMoveBack($masters, $slot, $from, $to);
			}
			finally
			{
				$this->leaveMoved($masters, [$store, $reader ?? null], $ids);
			}
		}
		
		return $written === [false, false, false]
			&& $read === ['old', null, null];
	}
	
	/**
	 * RULE: a Lua call that failed only after the client's read timeout is not sent again: it may have run,
	 * its reply still on the way. The key's master holds writes back well past the timeout: the write answers
	 * false once the timeout is up, and the client closes (see Functions::sendOrClose()). A held call goes
	 * with its connection, so the master never runs it; one sent again would run when writes are taken again
	 */
	public function aCallThatTookTheReadTimeoutIsNotSentAgain(): bool
	{
		$store = $this->movingStore();
		$masters = $this->masters($store);
		$key = 'timed-out-set:' . bin2hex(random_bytes(4));
		$id = $store->itemId($key);
		$node = $masters[$this->ownerOf($masters, $this->slotOf($masters, $id))][0];
		$timeoutMs = $this->readTimeoutMs($store);
		$store->get($key, queue: false);
		// a store of an earlier rule destroyed meanwhile would send calls of its own (a stale drop)
		gc_collect_cycles();
		$before = $this->commandCalls($node, 'fcall');
		
		try
		{
			$startedAt = $this->holdWrites($node, $timeoutMs);
			$written = $store->set($key, 'new', 60);
			$tookMs = (microtime(true) - $startedAt) * 1000;
			$this->awaitWrites($startedAt, $timeoutMs);
			$ran = $this->commandCalls($node, 'fcall') - $before;
		}
		finally
		{
			$node->rawCommand('CLIENT', 'UNPAUSE');
			$this->leaveMoved($masters, [$store], [$id]);
		}
		
		return $written === false
			&& $tookMs < $timeoutMs + 1000
			&& $ran === 0;
	}
	
	/**
	 * RULE: a tombstone call that failed only after the client's read timeout is followed by nothing - no
	 * typed tombstone either: the call may still run, and the master is held, so anything more would wait as
	 * long. The key's master holds writes back well past the timeout; the held call goes with the closed
	 * connection, so the item stays as it was and the delete answers false
	 */
	public function aDeleteThatTookTheReadTimeoutSendsNothingMore(): bool
	{
		$store = $this->movingStore();
		$masters = $this->masters($store);
		$key = 'timed-out-delete:' . bin2hex(random_bytes(4));
		$id = $store->itemId($key);
		$node = $masters[$this->ownerOf($masters, $this->slotOf($masters, $id))][0];
		$timeoutMs = $this->readTimeoutMs($store);
		$store->set($key, 'old', 60);
		// a store of an earlier rule destroyed meanwhile would send calls of its own (a stale drop)
		gc_collect_cycles();
		$before = $this->commandCalls($node, 'type');
		
		try
		{
			$startedAt = $this->holdWrites($node, $timeoutMs);
			$deleted = $store->delete($key);
			$this->awaitWrites($startedAt, $timeoutMs);
			// a typed tombstone would read the key's type (so would a Lua call that ran: commands inside a
			// script count too)
			$typed = $this->commandCalls($node, 'type') - $before;
			$fields = array_keys((array)$node->hGetAll($id));
		}
		finally
		{
			$node->rawCommand('CLIENT', 'UNPAUSE');
			$this->leaveMoved($masters, [$store], [$id]);
		}
		
		return $deleted === false
			&& $typed === 0
			&& in_array(KeyValueRedis::KEY_DATA, $fields, true);
	}
	
	/**
	 * RULE: a call that ran, but whose connection broke before its reply arrived, is sent again only when it
	 * is safe to run twice. phpredis throws the same for that as for a redirect, and at once. The master
	 * closes the connection after the call, its reply too big for the client output buffer limit set here: a
	 * call not marked repeatable runs once, a repeatable one twice
	 */
	public function aCallWhoseConnectionBrokeAfterItRanIsRepeatedOnlyWhenSafe(): bool
	{
		$store = $this->movingStore();
		$masters = $this->masters($store);
		$functions = new Functions(
			['redirect_probe' => __DIR__ . '/files/redirect-probe.lua'],
			$store->getConnection(),
			'tests-redirect-' . bin2hex(random_bytes(3)),
		);
		$list = 'redirect-probe:' . bin2hex(random_bytes(4));
		$node = $masters[$this->ownerOf($masters, $this->slotOf($masters, $list))][0];
		$functions->loadLibraries();
		$limit = (array)$node->rawCommand('CONFIG', 'GET', 'client-output-buffer-limit');
		$thrown = 0;
		
		try
		{
			$node->rawCommand('CONFIG', 'SET', 'client-output-buffer-limit', 'normal 1024 1024 0');
			foreach([false, true] as $repeatable)
			{
				try
				{
					$repeatable
						? $functions->callRepeatable('redirect_probe_append', [$list], ['repeatable'])
						: $functions->call('redirect_probe_append', [$list], ['once']);
				}
				// a reply cut off midway throws RedisException
				catch(RedisException|RedisClusterException)
				{
					$thrown++;
				}
			}
			$node->rawCommand('CONFIG', 'SET', 'client-output-buffer-limit', (string)($limit[1] ?? 'normal 0 0 0'));
			$runs = (array)$node->lRange($list, 0, -1);
		}
		finally
		{
			$node->rawCommand('CONFIG', 'SET', 'client-output-buffer-limit', (string)($limit[1] ?? 'normal 0 0 0'));
			foreach($masters as [$master])
			{
				$master->rawCommand('FUNCTION', 'DELETE', $functions->functionsPrefix('redirect_probe'));
			}
			$this->leaveMoved($masters, [$store], [$list]);
		}
		
		return $thrown === 2
			&& $runs === ['once', 'repeatable', 'repeatable'];
	}
	
	/**
	 * RULE: a Lua call that failed after the client's read timeout leaves no late reply behind: the client is
	 * closed (see Functions::sendOrClose()), and the next read of that master answers its own value. The
	 * key's master holds writes back past the timeout; the held call goes with the closed connection
	 */
	public function aTimedOutCallLeavesNoLateReplyForTheNextRead(): bool
	{
		$store = $this->movingStore();
		$masters = $this->masters($store);
		$key = 'late-reply:' . bin2hex(random_bytes(4));
		$id = $store->itemId($key);
		$owner = $this->ownerOf($masters, $this->slotOf($masters, $id));
		$node = $masters[$owner][0];
		// a plain key of the same master, read once the late reply is there
		do
		{
			$plain = 'late-reply-plain:' . bin2hex(random_bytes(4));
		}
		while($this->ownerOf($masters, $this->slotOf($masters, $plain)) !== $owner);
		$node->set($plain, 'own value');
		$timeoutMs = $this->readTimeoutMs($store);
		$client = $store->getClient();
		// the client has a connection to that master before the call
		$client->get($plain);
		
		try
		{
			$startedAt = $this->holdWrites($node, $timeoutMs);
			$written = $store->set($key, 'new', 60);
			$this->awaitWrites($startedAt, $timeoutMs);
			$read = $client->get($plain);
		}
		finally
		{
			$node->rawCommand('CLIENT', 'UNPAUSE');
			$this->leaveMoved($masters, [$store], [$id, $plain]);
		}
		
		return $written === false
			&& $read === 'own value';
	}
	
	/**
	 * RULE: when the typed read after a failed call fails too, a master is likely down: for
	 * Functions::TYPED_READ_PAUSE_MS no other failed call on that connection sends one - in any store of this
	 * process, and with APCu of this host (a new request under FPM) - and every delete answers false at once,
	 * with no typed tombstone. The clients here fail every keyed raw call and every typed read, as with a
	 * master that refuses connections
	 */
	public function aMasterThatFailsTheTypedReadIsNotAskedAgainWithinThePause(): bool
	{
		[$store, $client] = $this->storeWithScriptedCalls();
		[$other, $otherClient] = $this->storeWithScriptedCalls();
		$key = 'paused:' . bin2hex(random_bytes(4));
		foreach([$client, $otherClient] as $failing)
		{
			$failing->keyed = static fn(Closure $send): mixed => throw new RedisClusterException('Connection refused');
			$failing->failingReads = true;
		}
		// a pause an earlier rule left must not hold this one's first typed read back
		$this->forgetPause($store);
		
		try
		{
			$deleted = [$store->delete($key), $store->delete($key), $other->delete($key)];
		}
		finally
		{
			// the pause must not reach the rules after this one
			$this->forgetPause($store);
			$store->getConnection()->disconnect();
			$other->getConnection()->disconnect();
		}
		
		return $deleted === [false, false, false]
			&& $client->calls === ['exists' => 1, 'type' => 0]
			&& $otherClient->calls === ['exists' => 0, 'type' => 0];
	}
	
	/**
	 * RULE: the typed read after a failed call is claimed before it is sent (see Functions::claimTypedRead()):
	 * while another process of the host holds the claim (APCu), a failing call sends none and fails at once;
	 * a typed read that gets through leaves no claim behind
	 */
	public function aTypedReadIsClaimedBeforeItIsSent(): bool
	{
		[$store, $client] = $this->storeWithScriptedCalls();
		$claim = (fn(): ?string => $this->typedReadKey())->call($store->getFunctions());
		if($claim === null)
		{
			throw new SkipException('needs APCu (apc.enable_cli for the CLI)');
		}
		$key = 'claimed:' . bin2hex(random_bytes(4));
		$this->forgetPause($store);
		
		try
		{
			// another process probes the master: this one sends no typed read
			apcu_store($claim, microtime(true), 5);
			$client->keyed = static fn(Closure $send): mixed => throw new RedisClusterException('Connection refused');
			$whileClaimed = $store->delete($key);
			$readsWhileClaimed = $client->calls['exists'];
			apcu_delete($claim);
			// a redirect: the call fails once, its typed read gets through, and the call goes again
			$runs = 0;
			$client->keyed = static function(Closure $send) use (&$runs): mixed
			{
				if($runs++ === 0)
				{
					throw new RedisClusterException('Unable to send command to the specified node');
				}
				
				return $send();
			};
			$redirected = $store->delete($key);
			$left = apcu_fetch($claim);
		}
		finally
		{
			$client->keyed = null;
			$this->forgetPause($store);
			$client->del($store->itemId($key));
			$store->getConnection()->disconnect();
		}
		
		return $whileClaimed === false
			&& $readsWhileClaimed === 0
			&& $redirected
			&& $client->calls['exists'] === 1
			&& $left === false;
	}
	
	/**
	 * RULE: a delete whose call fails before it is sent - here the libraries cannot be loaded, as the master
	 * list fails - answers false and sends no typed read and no typed tombstone: those are only for a call
	 * sent a second time after its typed read got through (a migrating slot, see KeyValue\Redis::tombstone())
	 */
	public function aDeleteWhoseLibrariesFailSendsNoTypedTombstone(): bool
	{
		[$store, $client] = $this->storeWithScriptedCalls();
		$client->failingMasters = true;
		
		$deleted = $store->delete('libraries-fail:' . bin2hex(random_bytes(4)));
		$calls = $client->calls;
		$client->failingMasters = false;
		$store->getConnection()->disconnect();
		
		return $deleted === false
			&& $calls === ['exists' => 0, 'type' => 0];
	}
	
	/**
	 * RULE: a lock release that ran, but whose connection broke before its reply arrived, is sent again; the
	 * second run finds the lock gone, yet the release answers true (see MemoLock\Redis::releaseActiveLock())
	 */
	public function aLockReleaseSentAgainAnswersReleased(): bool
	{
		[$store, $client] = $this->storeWithScriptedCalls();
		$id = $store->itemId('release-again:' . bin2hex(random_bytes(4)));
		$memoLock = $store->getMemoLock();
		$locked = $memoLock->tryLock($id);
		$runs = 0;
		// the first release runs, then its connection breaks
		$client->keyed = static function(Closure $send) use (&$runs): mixed
		{
			if($runs++ === 0)
			{
				$send();
				
				throw new RedisClusterException('Unable to send command at the specified node');
			}
			
			return $send();
		};
		
		$released = $memoLock->releaseActiveLock($id);
		$client->keyed = null;
		$held = (int)$client->exists($memoLock->getPrefixer()->prefix(MemoLock::TYPE_LOCK, $id));
		$store->getConnection()->disconnect();
		
		return $locked
			&& $released
			&& $runs === 2
			&& $held === 0;
	}
	
	/**
	 * RULE: a lock release sent a second time whose second run answers an error is not counted as released;
	 * only a second run that found the lock gone is (see MemoLock\Redis::releaseActiveLock()). The first run
	 * fails before it is sent, the second meets a value of another type under the lock's key
	 */
	public function aLockReleaseWhoseSecondRunAnswersAnErrorIsNotReleased(): bool
	{
		[$store, $client] = $this->storeWithScriptedCalls();
		$id = $store->itemId('release-error:' . bin2hex(random_bytes(4)));
		$memoLock = $store->getMemoLock();
		$locked = $memoLock->tryLock($id);
		$lockKey = $memoLock->getPrefixer()->prefix(MemoLock::TYPE_LOCK, $id);
		$runs = 0;
		$client->keyed = static function(Closure $send) use (&$runs, $client, $lockKey): mixed
		{
			if($runs++ === 0)
			{
				throw new RedisClusterException('Unable to send command to the specified node');
			}
			$client->keyed = null;
			
			// an error reply in place of the second run: the lock's key holds a string
			return $client->rawCommand($lockKey, 'LPUSH', $lockKey, 'x');
		};
		
		$released = $memoLock->releaseActiveLock($id);
		$client->keyed = null;
		$held = (int)$client->exists($lockKey);
		$client->del($lockKey);
		$this->forgetPause($store);
		$store->getConnection()->disconnect();
		
		return $locked
			&& $released === false
			&& $runs === 2
			&& $held === 1;
	}
	
	/**
	 * RULE: a raw call that fails at once on a redirect closes no connection: closing would drop the client's
	 * connection to every master (see Functions::sendOrClose()). After a hand-over, the client's connection to
	 * a master the slot has nothing to do with is the same before and after the write
	 */
	public function aRedirectKeepsTheConnectionsToTheOtherMasters(): bool
	{
		$writer = $this->movingStore();
		$masters = $this->masters($writer);
		$key = $this->keyAwayFromTheRules($writer, $masters, 'redirect-keeps');
		$id = $writer->itemId($key);
		$slot = $this->slotOf($masters, $id);
		$from = $this->ownerOf($masters, $slot);
		$to = $this->otherMaster($masters, $from);
		$third = array_values(array_diff(array_keys($masters), [$from, $to]))[0] ?? null;
		if($third === null)
		{
			throw new SkipException('needs a third master');
		}
		[$host, $port] = explode(':', $third);
		$client = $writer->getClient();
		// the writer's read misses: its write after it is guarded, and sent again after the redirect
		$writer->get($key, queue: false);
		
		try
		{
			$this->handOver($masters, $slot, $from, $to);
			$before = $client->rawCommand([$host, (int)$port], 'CLIENT', 'ID');
			$written = $writer->set($key, 'new', 60);
			$after = $client->rawCommand([$host, (int)$port], 'CLIENT', 'ID');
		}
		finally
		{
			try
			{
				$this->moveBack($masters, $slot, $to, $from);
			}
			finally
			{
				$this->leaveMoved($masters, [$writer], [$id]);
			}
		}
		
		return $written
			&& $before === $after;
	}
	
	/**
	 * Removes the claim and the pause of a typed read on $store's connection, in this process and in APCu
	 * (see Functions::claimTypedRead())
	 */
	protected function forgetPause(
		Store $store,
	): void
	{
		(function(): void
		{
			static::$typedReadFailedAt = [];
			if(($key = $this->typedReadKey()) !== null)
			{
				apcu_delete($key);
			}
		})->call($store->getFunctions());
	}
	
	/**
	 * RULE: a tombstone call that ran, but whose connection broke before its reply arrived, is sent again
	 * (see Functions::wasSentAgain()); the second run finds no item, yet the delete answers true, as the
	 * first run removed it. A delete of a missing key, sent once, answers false
	 */
	public function aTombstoneSentAgainAnswersThatTheItemWasThere(): bool
	{
		[$store, $client] = $this->storeWithScriptedCalls();
		$key = 'sent-again:' . bin2hex(random_bytes(4));
		$missing = 'sent-again-missing:' . bin2hex(random_bytes(4));
		$id = $store->itemId($key);
		$store->set($key, 'old', 60);
		$missed = $store->delete($missing);
		$once = $store->getFunctions()->wasSentAgain();
		$runs = 0;
		// the first tombstone call runs, then its connection breaks
		$client->keyed = static function(Closure $send) use (&$runs): mixed
		{
			if($runs++ === 0)
			{
				$send();
				
				throw new RedisClusterException('Unable to send command at the specified node');
			}
			
			return $send();
		};
		
		$deleted = $store->delete($key);
		$again = $store->getFunctions()->wasSentAgain();
		$client->keyed = null;
		$fields = array_keys((array)$client->hGetAll($id));
		$client->del($id);
		$client->del($store->itemId($missing));
		$store->getConnection()->disconnect();
		
		return $missed === false
			&& $once === false
			&& $deleted
			&& $again
			&& $runs === 2
			&& $fields === [KeyValueRedis::KEY_EPOCH];
	}
	
	/**
	 * A cluster store whose client hands each keyed raw call to its $keyed, which runs it, throws, or both;
	 * with $failingReads its typed EXISTS throws, with $failingMasters its master list. It counts the EXISTS
	 * and TYPE it is sent
	 *
	 * @return array{0: Store, 1: RedisClusterClient}
	 */
	protected function storeWithScriptedCalls(): array
	{
		$store = $this->movingStore();
		$connection = $store->getConnection();
		$real = $connection->getClient();
		$config = $connection->getConfig();
		$client = new class(
			null,
			$config->getArray('seeds'),
			2.0,
			$connection->getReadTimeout(),
			false,
			$config->auth,
		) extends RedisClusterClient
		{
			/**
			 * Gets the real send of a keyed raw call, answers in its place (null: the call goes as it is)
			 */
			public ?Closure $keyed = null;
			
			public bool $failingReads = false;
			
			/**
			 * The master list throws: the libraries cannot be loaded
			 */
			public bool $failingMasters = false;
			
			/**
			 * @var array{exists: int, type: int}
			 */
			public array $calls = ['exists' => 0, 'type' => 0];
			
			#[Override]
			public function rawCommand(
				array|string $key_or_address,
				string $command,
				mixed ...$args,
			): mixed
			{
				$send = fn(): mixed => parent::rawCommand($key_or_address, $command, ...$args);
				
				return is_string($key_or_address) && $this->keyed !== null
					? ($this->keyed)($send)
					: $send();
			}
			
			#[Override]
			public function exists(
				mixed $key,
				mixed ...$other_keys,
			): RedisClusterClient|int|bool
			{
				$this->calls['exists']++;
				if($this->failingReads)
				{
					throw new RedisClusterException('Connection refused');
				}
				
				return parent::exists($key, ...$other_keys);
			}
			
			#[Override]
			public function _masters(): array
			{
				if($this->failingMasters)
				{
					throw new RedisClusterException('Connection refused');
				}
				
				return parent::_masters();
			}
			
			#[Override]
			public function type(
				string $key,
			): RedisClusterClient|int|false
			{
				$this->calls['type']++;
				
				return parent::type($key);
			}
		};
		foreach([
			RedisClient::OPT_SERIALIZER,
			RedisClient::OPT_REPLY_LITERAL,
			RedisClusterClient::OPT_SLAVE_FAILOVER,
		] as $option)
		{
			$client->setOption($option, $real->getOption($option));
		}
		$real->close();
		(fn() => $this->client = $client)->call($connection);
		
		return [$store, $client];
	}
	
	/**
	 * Skips a rule that moves slots or holds a master's writes unless the environment allows it: such a rule
	 * needs a cluster nobody else uses (the tests' own, as CI starts it), never one holding real data, and no
	 * other test class using it meanwhile (the runner runs the classes one after another). Also when a slot is
	 * migrating already, left so by a run that broke off: nothing is moved then
	 *
	 * @param array<string, array{0: RedisClient, 1: string}> $masters
	 */
	protected function requireSlotMoves(
		array $masters,
	): void
	{
		if(config()->getPath(['tests_options', 'exclusive_servers']) !== true)
		{
			throw new SkipException('moves cluster slots and holds writes: set TESTS[EXCLUSIVE_SERVERS] = yes'
				. ' in ci/.env, only for a cluster nobody else uses, no other test class at the same time');
		}
		
		foreach($masters as $address => [$node])
		{
			if(preg_match('/myself.*\[\d+-[<>]-/', (string)$node->rawCommand('CLUSTER', 'NODES')) === 1)
			{
				throw new SkipException('a slot on ' . $address
					. ' is migrating already: CLUSTER SETSLOT <slot> STABLE first');
			}
		}
	}
	
	/**
	 * A cluster store on a connection of its own, its rules read exactly: its client keeps the slot map it
	 * read until a command of its own learns otherwise
	 */
	protected function movingStore(): Store
	{
		return $this->getClusterStore([
			'rules_cache_ms' => 0,
			'rules_shared_cache' => false,
		], 'moving-' . bin2hex(random_bytes(4)));
	}
	
	/**
	 * A connection to every master, by address, with its node id - with the cluster connection's auth.
	 * Skips the rule where moving slots is not allowed (see requireSlotMoves())
	 *
	 * @return array<string, array{0: RedisClient, 1: string}>
	 */
	protected function masters(
		Store $store,
	): array
	{
		$auth = $store->getConnection()->getConfig()->auth;
		$masters = [];
		foreach($store->getClient()->_masters() as [$host, $port])
		{
			$node = new RedisClient();
			try
			{
				$connected = $node->connect((string)$host, (int)$port, 2.0, null, 0, 5.0)
					&& ($auth === null || $node->auth($auth));
			}
			catch(RedisException)
			{
				$connected = false;
			}
			if($connected === false)
			{
				throw new SkipException('master ' . $host . ':' . $port . ' cannot be reached directly');
			}
			$masters[$host . ':' . $port] = [$node, (string)$node->rawCommand('CLUSTER', 'MYID')];
		}
		ksort($masters);
		$this->requireSlotMoves($masters);
		
		return $masters;
	}
	
	/**
	 * A key of $store whose slot is not the rules stream's: a write reads the rules first, and that typed read
	 * would bring the slot map up to date by itself
	 *
	 * @param array<string, array{0: RedisClient, 1: string}> $masters
	 */
	protected function keyAwayFromTheRules(
		Store $store,
		array $masters,
		string $name,
	): string
	{
		$rulesSlot = $this->slotOf($masters, $store->getRulesKey());
		do
		{
			$key = $name . ':' . bin2hex(random_bytes(4));
		}
		while($this->slotOf($masters, $store->itemId($key)) === $rulesSlot);
		
		return $key;
	}
	
	/**
	 * The slot of $key
	 *
	 * @param array<string, array{0: RedisClient, 1: string}> $masters
	 */
	protected function slotOf(
		array $masters,
		string $key,
	): int
	{
		return (int)reset($masters)[0]->rawCommand('CLUSTER', 'KEYSLOT', $key);
	}
	
	/**
	 * The address of the master that owns $slot, as the first master sees it
	 *
	 * @param array<string, array{0: RedisClient, 1: string}> $masters
	 */
	protected function ownerOf(
		array $masters,
		int $slot,
	): string
	{
		return $this->ownerSeenBy(reset($masters)[0], $slot);
	}
	
	/**
	 * The address of the master that owns $slot, as $node sees it
	 */
	protected function ownerSeenBy(
		RedisClient $node,
		int $slot,
	): string
	{
		foreach($node->rawCommand('CLUSTER', 'SLOTS') as $range)
		{
			if($slot >= $range[0] && $slot <= $range[1])
			{
				return $range[2][0] . ':' . $range[2][1];
			}
		}
		
		throw new RuntimeException('slot ' . $slot . ' is not covered');
	}
	
	/**
	 * The master after $address
	 *
	 * @param array<string, array{0: RedisClient, 1: string}> $masters
	 */
	protected function otherMaster(
		array $masters,
		string $address,
	): string
	{
		$addresses = array_keys($masters);
		
		return $addresses[((int)array_search($address, $addresses, true) + 1) % count($addresses)];
	}
	
	/**
	 * Starts moving $slot from $from to $to - IMPORTING on the new master first, then MIGRATING on the old
	 * one - and moves $keys there already: the old master answers ASK for them, and for every key it does not
	 * hold
	 *
	 * @param array<string, array{0: RedisClient, 1: string}> $masters
	 * @param list<string> $keys
	 */
	protected function startMigration(
		array $masters,
		int $slot,
		string $from,
		string $to,
		array $keys,
	): void
	{
		$masters[$to][0]->rawCommand('CLUSTER', 'SETSLOT', (string)$slot, 'IMPORTING', $masters[$from][1]);
		$masters[$from][0]->rawCommand('CLUSTER', 'SETSLOT', (string)$slot, 'MIGRATING', $masters[$to][1]);
		if($keys !== [])
		{
			[$host, $port] = explode(':', $to);
			$masters[$from][0]->rawCommand('MIGRATE', $host, $port, '', '0', '5000', 'KEYS', ...$keys);
		}
	}
	
	/**
	 * Hands $slot over from $from to $to: the migration started (or carried on), every key still on the old
	 * master moved, the new owner set on the new master first, then on the old one, then on the rest; waits
	 * until every master agrees. The new owner's config epoch must be the highest, and known to every master,
	 * before the others are told: otherwise a claim the old owner sent earlier, with its higher epoch, can
	 * win on a master afterwards, and the slot then has two owners on different masters
	 *
	 * @param array<string, array{0: RedisClient, 1: string}> $masters
	 */
	protected function handOver(
		array $masters,
		int $slot,
		string $from,
		string $to,
	): void
	{
		$this->awaitEpochs($masters);
		$this->startMigration($masters, $slot, $from, $to, []);
		while(($keys = $masters[$from][0]->rawCommand('CLUSTER', 'GETKEYSINSLOT', (string)$slot, '100')) !== [])
		{
			$this->startMigration($masters, $slot, $from, $to, $keys);
		}
		
		$masters[$to][0]->rawCommand('CLUSTER', 'SETSLOT', (string)$slot, 'NODE', $masters[$to][1]);
		$masters[$to][0]->rawCommand('CLUSTER', 'BUMPEPOCH');
		$this->awaitEpochs($masters, $masters[$to][1]);
		foreach([$from, ...array_values(array_diff(array_keys($masters), [$to, $from]))] as $address)
		{
			$masters[$address][0]->rawCommand('CLUSTER', 'SETSLOT', (string)$slot, 'NODE', $masters[$to][1]);
		}
		$this->awaitSlot($masters, $slot, $to);
		$this->awaitEpochs($masters, $masters[$to][1]);
		$this->awaitSlot($masters, $slot, $to);
	}
	
	/**
	 * Hands $slot back from $to to $from, and leaves no migration of it on any master, also when that threw
	 * half way
	 *
	 * @param array<string, array{0: RedisClient, 1: string}> $masters
	 */
	protected function moveBack(
		array $masters,
		int $slot,
		string $to,
		string $from,
	): void
	{
		try
		{
			$this->handOver($masters, $slot, $to, $from);
		}
		finally
		{
			$this->stabilize($masters, $slot);
		}
	}
	
	/**
	 * Ends a migration $startMigration() began: hands $slot over to $to, then back to $from
	 *
	 * @param array<string, array{0: RedisClient, 1: string}> $masters
	 */
	protected function finishAndMoveBack(
		array $masters,
		int $slot,
		string $from,
		string $to,
	): void
	{
		try
		{
			$this->handOver($masters, $slot, $from, $to);
		}
		finally
		{
			$this->moveBack($masters, $slot, $to, $from);
		}
	}
	
	/**
	 * Clears any migration of $slot on every master (SETSLOT STABLE), whatever is left of a hand-over that
	 * threw half way; a master that does not answer is passed over
	 *
	 * @param array<string, array{0: RedisClient, 1: string}> $masters
	 */
	protected function stabilize(
		array $masters,
		int $slot,
	): void
	{
		foreach($masters as [$node])
		{
			try
			{
				$node->rawCommand('CLUSTER', 'SETSLOT', (string)$slot, 'STABLE');
			}
			catch(RedisException)
			{
				// the others are cleared still
			}
		}
	}
	
	/**
	 * Waits until every master sees the same current epoch and the same config epoch for every node - with
	 * the node $highest's above every other's, when given
	 *
	 * @param array<string, array{0: RedisClient, 1: string}> $masters
	 */
	protected function awaitEpochs(
		array $masters,
		?string $highest = null,
	): void
	{
		$until = microtime(true) + 10;
		do
		{
			$views = [];
			foreach($masters as [$node])
			{
				preg_match('/cluster_current_epoch:(\d+)/', (string)$node->rawCommand('CLUSTER', 'INFO'), $current);
				$epochs = [];
				foreach(explode("\n", trim((string)$node->rawCommand('CLUSTER', 'NODES'))) as $line)
				{
					$fields = explode(' ', $line);
					$epochs[$fields[0]] = (int)($fields[6] ?? -1);
				}
				ksort($epochs);
				$views[] = [(int)($current[1] ?? -1), $epochs];
			}
			
			$agreed = count(array_unique(array_map('serialize', $views))) === 1;
			if($agreed
				&& $highest !== null)
			{
				$epochs = $views[0][1];
				$others = $epochs;
				unset($others[$highest]);
				$agreed = ($epochs[$highest] ?? -1) > max($others);
			}
			if($agreed)
			{
				return;
			}
			usleep(50000);
		}
		while(microtime(true) < $until);
		
		throw new RuntimeException('the masters do not agree on the config epochs');
	}
	
	/**
	 * Waits until every master reports the cluster ok, gives $slot to $owner and migrates it no more
	 *
	 * @param array<string, array{0: RedisClient, 1: string}> $masters
	 */
	protected function awaitSlot(
		array $masters,
		int $slot,
		string $owner,
	): void
	{
		$until = microtime(true) + 10;
		do
		{
			$settled = true;
			foreach($masters as [$node])
			{
				if(str_contains((string)$node->rawCommand('CLUSTER', 'INFO'), 'cluster_state:ok') === false
					|| $this->ownerSeenBy($node, $slot) !== $owner
					|| preg_match('/\[' . $slot . '-[<>]-/', (string)$node->rawCommand('CLUSTER', 'NODES')) === 1)
				{
					$settled = false;
					
					break;
				}
			}
			if($settled)
			{
				return;
			}
			usleep(50000);
		}
		while(microtime(true) < $until);
		
		throw new RuntimeException('slot ' . $slot . ' did not settle on ' . $owner);
	}
	
	/**
	 * The fields $key holds on the importing master $node (ASKING, so it answers for a slot it imports)
	 *
	 * @return list<string>
	 */
	protected function fieldsWhileImporting(
		RedisClient $node,
		string $key,
	): array
	{
		$node->rawCommand('ASKING');
		$fields = $node->rawCommand('HKEYS', $key);
		
		return is_array($fields)
			? $fields
			: [];
	}
	
	/**
	 * The client's read timeout in ms; skips a rule where it is none or too long to wait for
	 */
	protected function readTimeoutMs(
		Store $store,
	): float
	{
		$timeoutMs = (float)$store->getClient()->getOption(RedisClient::OPT_READ_TIMEOUT) * 1000;
		if($timeoutMs <= 0
			|| $timeoutMs > 3000)
		{
			throw new SkipException('the cluster client\'s read timeout is not between 0 and 3 s');
		}
		
		return $timeoutMs;
	}
	
	/**
	 * Makes $node hold every write back for 1.5 s longer than $timeoutMs - a call sent again would be held as
	 * long; when it began
	 */
	protected function holdWrites(
		RedisClient $node,
		float $timeoutMs,
	): float
	{
		$node->rawCommand('CLIENT', 'PAUSE', (string)(int)($timeoutMs + 1500), 'WRITE');
		
		return microtime(true);
	}
	
	/**
	 * Waits until the master takes writes again (see holdWrites()), and a little longer
	 */
	protected function awaitWrites(
		float $startedAt,
		float $timeoutMs,
	): void
	{
		$left = $startedAt + ($timeoutMs + 1800) / 1000 - microtime(true);
		if($left > 0)
		{
			usleep((int)($left * 1000000));
		}
	}
	
	/**
	 * How often $node ran $command (INFO commandstats)
	 */
	protected function commandCalls(
		RedisClient $node,
		string $command,
	): int
	{
		$stats = (string)$node->rawCommand('INFO', 'commandstats');
		
		return preg_match('/^cmdstat_' . $command . ':calls=(\d+)/m', $stats, $match) === 1
			? (int)$match[1]
			: 0;
	}
	
	/**
	 * Removes $keys and lets go of the stores' connections and the masters'
	 *
	 * @param array<string, array{0: RedisClient, 1: string}> $masters
	 * @param list<?Store> $stores
	 * @param list<string> $keys
	 */
	protected function leaveMoved(
		array $masters,
		array $stores,
		array $keys,
	): void
	{
		foreach($keys as $key)
		{
			$masters[$this->ownerOf($masters, $this->slotOf($masters, $key))][0]->unlink($key);
		}
		foreach($stores as $store)
		{
			$store?->getConnection()->disconnect();
		}
		foreach($masters as [$node])
		{
			$node->close();
		}
	}
	
	/**
	 * Called by the runner after each test method
	 */
	#[Internal]
	#[Override]
	public function finalize(): void
	{
		$this->store?->clearPhysical();
		// a fresh store for the next rule: this one remembers its misses
		// (the invalidation guard - see KeyValue::rememberMiss())
		$this->store = $this->getClusterStore();
	}
	
	/**
	 * Called by the runner after all test methods have been invoked
	 */
	#[Internal]
	#[Override]
	public function deconstruct(): void
	{
		$this->store?->getConnection()
			->disconnect();
	}
	
	/**
	 * RULE: a batch on a cluster falls back to single calls - the reads, the
	 * locks and their looks, the writes and the releases one by one (the keys
	 * are of different slots) - and answers as on a standalone server: hits,
	 * misses computed by one call, a stale value refreshed by another
	 */
	public function aBatchOnAClusterFallsBackToSingleCalls(): bool
	{
		$store = $this->staleStore();
		[$hit, $first, $second, $aged] = $keys = $this->batchKeys('cluster', 4);
		$store->set($hit, 'hit', 60);
		$this->writeAged($store, $aged, 'old');
		$calls = [];
		
		$values = $this->staleStore()->getMany($keys, $this->batchResolver($calls), 60, stale: 60);
		$again = $this->staleStore()->getMany($keys);
		$this->batchDelete($keys);
		$computed = ['hit', 'computed ' . $first, 'computed ' . $second, 'computed ' . $aged];
		
		return $values === $computed
			&& $again === $computed
			&& $calls === [[$aged], [$first, $second]];
	}
	
	/**
	 * No single standalone client to count the round trips of (TraitBatch)
	 */
	protected function batchProbe(): ?KeyValueRedis
	{
		return null;
	}
	
	/**
	 * A fresh store - another process (TraitStaleWhileRevalidate)
	 */
	protected function staleStore(): KeyValueRedis
	{
		return $this->guardStore();
	}
	
	/**
	 * A fresh store - another process (TraitInvalidationGuard); its rules read
	 * exactly, so a reader sees an invalidation at once rather than within
	 * rules_cache_ms (the store's documented staleness window)
	 */
	protected function guardStore(
		array $storeOptions = [],
	): KeyValueRedis
	{
		return $this->getClusterStore($storeOptions + [
			'rules_cache_ms' => 0,
			'rules_shared_cache' => false,
		]);
	}
	
	/**
	 * RULE: a stale item's cleanup removes only that item - a delete() that
	 * left a tombstone between the read and the cleanup keeps it (an UNLINK
	 * erased it, and a recomputation that missed before the delete passed the
	 * guard); the epoch an item carries stays when its data goes
	 */
	public function aStaleCleanupKeepsANewerTombstone(): bool
	{
		$store = $this->guardStore();
		$client = $store->getClient();
		$key = $this->guardKey('stale');
		$id = $store->prefix($key, $store->getType());
		
		$store->set($key, 'x', 60);
		$mark = (string)$client->hGet($id, 'mark');
		$store->delete($key);
		$token = $client->hGet($id, KeyValueRedis::KEY_EPOCH);
		$kept = (int)$store->getFunctions()->call('cache_versioned_drop_stale', [$id], [$mark]) === 0
			&& $client->hGet($id, KeyValueRedis::KEY_EPOCH) === $token;
		
		// an item carrying an epoch loses its data, keeps the epoch
		$writer = $this->guardStore();
		$writer->set($key, 'y', 60);
		$mark = (string)$client->hGet($id, 'mark');
		$stripped = (int)$store->getFunctions()->call('cache_versioned_drop_stale', [$id], [$mark]) === 1
			&& array_keys((array)$client->hGetAll($id)) === [KeyValueRedis::KEY_EPOCH];
		$store->delete($key);
		
		return $kept && $stripped;
	}
	
	/**
	 * RULE: a stale item's cleanup keeps its epoch for the window, not for the
	 * item's remaining lifetime: a delete or a write is seen by the writes made
	 * within the window after it, and a write whose miss saw the epoch is taken
	 * whether it is still there or not, so an epoch-only hash kept for up to the
	 * retention is memory and nothing else
	 */
	public function aStaleCleanupKeepsTheEpochForTheWindowOnly(): bool
	{
		$writer = $this->guardStore();
		$client = $writer->getClient();
		$key = $this->guardKey('stale-window');
		$id = $writer->prefix($key, $writer->getType());
		
		// a write-through (no miss before it), so the item carries an epoch
		$writer->set($key, 'x', 3600, [self::GUARD_TAG]);
		$writer->invalidateTags([self::GUARD_TAG]);
		
		$reader = $this->guardStore();
		$read = $reader->get($key, queue: false);
		$fields = array_keys((array)$client->hGetAll($id));
		$ttl = (int)$client->pTtl($id);
		$reader->delete($key);
		
		return $read === null
			&& $fields === [KeyValueRedis::KEY_EPOCH]
			&& $ttl > 0
			&& $ttl <= $reader->getInvalidationWindowMs();
	}
	
	/**
	 * RULE: a reader whose held rule set lags (rules_cache_ms) still never
	 * serves a value computed before a tag invalidation: its miss stamps the
	 * older watermark it holds, so the rule reads as newer
	 */
	public function aLaggingRuleSetStillGuards(): bool
	{
		$reader = $this->guardStore(['rules_cache_ms' => 1000, 'rules_shared_cache' => false]);
		$writer = $this->guardStore();
		$key = $this->guardKey('lag');
		
		$reader->get($key, queue: false);
		$writer->invalidateTags([self::GUARD_TAG]);
		$reader->set($key, 'stale', 60, [self::GUARD_TAG]);
		$read = $this->guardStore()->get($key, queue: false);
		$writer->delete($key);
		
		return $read === null;
	}
}
