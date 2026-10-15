<?php
declare(strict_types=1);

namespace Tests\Cache\Store;

use Ovos\Cache\MemoLock\Redis as MemoLock;
use Ovos\Cache\Store\RedisVersioned as Store;
use Ovos\Cache\Versioned\Rules;
use Ovos\Cache\Versioned\SharedRules;
use Ovos\Cache\Store\KeyValue\Redis as KeyValueRedis;
use Ovos\Cache\Store\KeyValue\TagIndex;
use Ovos\Cache\Store\KeyValue\Tags;
use Ovos\Cache\Store\Redis as RedisStore;
use Ovos\Cache\Store\Redisearch;
use Ovos\Test;
use Ovos\Test\Internal;
use Ovos\Test\Cache\Store\RedisVersionedProbe;
use Ovos\Test\Cache\Store\TraitInvalidationGuard;
use Ovos\Test\Cache\Store\TraitStaleWhileRevalidate;
use Ovos\Test\Cache\Store\TraitCachePolicy;
use Ovos\Test\Cache\Store\TraitStoredStrings;
use Ovos\Test\Cache\Store\TraitRedis;
use Ovos\Test\Exception\SkipException;
use Override;
use RedisException;
use ReflectionMethod;

use function array_column;
use function array_keys;
use function bin2hex;
use function count;
use function defined;
use function end;
use function in_array;
use function is_subclass_of;
use function method_exists;
use function microtime;
use function random_bytes;
use function str_ends_with;
use function usleep;

/**
 * RedisVersioned
 *
 * The rule based (logical) tag invalidation on a standalone Redis -
 * invalidations append rules instead of deleting items, reads evaluate
 * the rules server side in one Lua call.
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class RedisVersioned extends Test
{
	use TraitRedis;
	use TraitInvalidationGuard;
	use TraitStaleWhileRevalidate;
	use TraitStoredStrings;
	use TraitCachePolicy;
	
	public const string KEY_ITEM = 'item';
	
	protected ?Store $store = null;
	
	public function __construct()
	{
		$this->store = $this->getStore(Store::class);
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
	 * An item written after the invalidation must survive it
	 * (the rules only match items older than themselves)
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
	 * (evicted, deleted, gone with its slot) before anyone read the item
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
	 * An invalidation the server refused (an error reply, not an exception)
	 * is logged - never lost quietly - and reported false
	 */
	public function aRefusedInvalidationIsLogged(): bool
	{
		// a group of its own: its rules key is broken on purpose
		$group = $this->group;
		$this->group = 'tests-refused-' . bin2hex(random_bytes(4));
		try
		{
			$probe = $this->probe();
		}
		finally
		{
			$this->group = $group;
		}
		$client = $probe->getClient();
		$client->set($probe->getRulesKey(), 'not a stream');
		
		try
		{
			return $probe->invalidateTags(['tag1']) === false
				&& count($probe->logged) === 1;
		}
		finally
		{
			$client->del($probe->getRulesKey());
		}
	}
	
	/**
	 * An invalidated hard value is not decoded to learn that it is not soft:
	 * the write stores whether it is, read with the item. A soft value is
	 * still decoded for its verdict, and so is an item written before the
	 * field
	 */
	public function anInvalidatedHardValueIsNotDecodedForTheSoftVerdict(): bool
	{
		$probe = $this->probe(['rules_cache_ms' => 0]);
		$probe->set('hard', 'old', tags: ['tag1']);
		$probe->get('soft', fn() => 'old', 60, ['tag1'], stale: 60, soft: true);
		$probe->get('legacy', fn() => 'old', 60, ['tag1'], stale: 60, soft: true);
		$probe->getClient()
			->hDel($probe->prefix('legacy', $probe->getType()), $probe::KEY_SOFT);
		$probe->invalidateTags(['tag1']);
		
		$probe->get('hard', queue: false);
		$hard = $probe->softlyCalls;
		$probe->get('soft', queue: false);
		$soft = $probe->softlyCalls;
		$probe->get('legacy', queue: false);
		$legacy = $probe->softlyCalls;
		
		try
		{
			return $hard === 0
				&& $soft === 1
				&& $legacy === 2;
		}
		finally
		{
			foreach(['hard', 'soft', 'legacy'] as $item)
			{
				$probe->delete($item);
			}
		}
	}
	
	/**
	 * A recomputation after an invalidation reads in one round trip: the
	 * stale item is not dropped first - the write that follows overwrites it.
	 * A read nothing is written after (no resolver, a null result) still
	 * drops it, as before
	 */
	public function aStaleItemIsDroppedOnlyWhenNothingOverwritesIt(): bool
	{
		$probe = $this->probe(['rules_cache_ms' => 0]);
		foreach(['item1', 'item2', 'item3', 'item4'] as $item)
		{
			$probe->set($item, 'old', tags: ['tag1']);
		}
		$probe->invalidateTags(['tag1']);
		
		$recomputed = $probe->get('item1', fn() => 'new', 60, ['tag1']);
		$afterWrite = $probe->dropped;
		$plain = $probe->get('item2', queue: false);
		$afterRead = $probe->dropped;
		$null = $probe->get('item3', fn() => null, 60, ['tag1']);
		$afterNull = $probe->dropped;
		$peeked = $probe->peek('item4');
		$afterPeek = $probe->dropped;
		$dataLeft = $probe->getClient()
			->hExists($probe->prefix('item2', $probe->getType()), 'data');
		
		try
		{
			return $recomputed === 'new'
				&& $probe->get('item1', queue: false) === 'new'
				&& $afterWrite === 0
				&& $plain === null && $afterRead === 1
				&& $null === null && $afterNull === 2
				&& $peeked === null && $afterPeek === 3
				&& $dataLeft === false;
		}
		finally
		{
			foreach(['item1', 'item2', 'item3', 'item4'] as $item)
			{
				$probe->delete($item);
			}
		}
	}
	
	/**
	 * A read no afterRead() ends (lockAndQueue(), a critical section nothing
	 * is written after) leaves its stale item to the store's end, the
	 * request's - unless a delete() replaced the item with its tombstone
	 * meanwhile
	 */
	public function whatAReadLeavesWithoutItsEndIsDroppedWhenTheStoreGoes(): bool
	{
		$probe = $this->probe(['rules_cache_ms' => 0]);
		foreach(['item1', 'item2'] as $item)
		{
			$probe->set($item, 'old', tags: ['tag1']);
		}
		$probe->invalidateTags(['tag1']);
		$client = $probe->getClient();
		$id = $probe->prefix('item1', $probe->getType());
		
		try
		{
			$probe->lockAndQueue('item1');
			$probe->releaseActiveLock('item1');
			$probe->lockAndQueue('item2');
			$probe->delete('item2');
			$probe->releaseActiveLock('item2');
			$pending = $probe->dropped;
			$present = $client->hExists($id, 'data');
			// the end of the request
			$probe->__destruct();
			
			return $pending === 0
				&& $present === true
				&& $probe->dropped === 1
				&& $client->hExists($id, 'data') === false;
		}
		finally
		{
			foreach(['item1', 'item2'] as $item)
			{
				$probe->delete($item);
			}
		}
	}
	
	/**
	 * A write that follows no miss is stamped with the watermark its writer
	 * holds, never the stream's head: a rule another server appended within
	 * the writer's rules_cache_ms is one it never held, and a value computed
	 * before it must not count as having seen it. The writer's own
	 * invalidation is held at once - its own write after it stays fresh
	 */
	public function aWriteThroughIsStampedWithTheRulesItsWriterHolds(): bool
	{
		$writer = $this->probe(['rules_cache_ms' => 60000]);
		$reader = $this->probe(['rules_cache_ms' => 0]);
		// the writer holds the rule set as it is now
		$writer->rules();
		// another server invalidates tag1: straight into the stream, no mark on
		// this host (an invalidation made here would make the writer refresh)
		$writer->getClient()
			->xAdd($writer->getRulesKey(), '*', ['mode' => 'any', 'tags' => 'tag1', 'first' => '0']);
		$writer->set('item1', 'computed before', tags: ['tag1']);
		$writer->invalidateTags(['tag2']);
		$writer->set('item2', 'computed after', tags: ['tag2']);
		
		try
		{
			return $reader->get('item1', queue: false) === null
				&& $reader->get('item2', queue: false) === 'computed after';
		}
		finally
		{
			$writer->delete('item1');
			$writer->delete('item2');
		}
	}
	
	/**
	 * A miss takes the lock with its second look in one round trip (SET NX and
	 * the HMGET, pipelined), and the value it computed goes out with the
	 * lock's release in another (both FCALLs) - three round trips instead of
	 * five; the waiters are told as the value lands
	 */
	public function aMissTakesTheLockWithItsLookAndReleasesItWithTheWrite(): bool
	{
		$probe = $this->probe();
		$key = 'p2_' . bin2hex(random_bytes(4));
		$lockKey = $probe->getMemoLock()
			->getPrefixer()
			->prefix(MemoLock::TYPE_LOCK, $probe->prefix($key, $probe->getType()));
		
		try
		{
			$value = $probe->get($key, fn() => 'computed', 60);
			$sent = $probe->sentTogether;
			
			return $value === 'computed'
				// the first read, then the lock with its look - no second fetch()
				&& $probe->fetches === 1
				&& $probe->lookedWithLock === 1
				// the write and the release together, no release on its own
				&& count($sent) === 1
				&& str_ends_with($sent[0][0], 'cache_versioned_set_guarded')
				&& str_ends_with($sent[0][1], 'memolock_release_lock_and_publish')
				&& $probe->releasesAlone === 0
				&& $probe->getClient()->exists($lockKey) === 0
				&& $probe->get($key, queue: false) === 'computed';
		}
		finally
		{
			$probe->delete($key);
		}
	}
	
	/**
	 * A value another process wrote between this one's miss and its lock is
	 * found by the look made with the lock: nothing is computed, the lock is
	 * released at once
	 */
	public function aValueLandingBeforeTheLockIsFoundByTheLookMadeWithIt(): bool
	{
		$probe = $this->probe();
		$key = 'p2_' . bin2hex(random_bytes(4));
		$computed = false;
		$probe->beforeLock = function() use ($key): void
		{
			$this->store->set($key, 'landed');
		};
		
		try
		{
			$value = $probe->get($key, function() use (&$computed): string
			{
				$computed = true;
				
				return 'computed';
			}, 60);
			
			return $value === 'landed'
				&& $computed === false
				&& $probe->fetches === 1
				&& $probe->lookedWithLock === 1
				&& $probe->releasesAlone === 1;
		}
		finally
		{
			$probe->delete($key);
		}
	}
	
	/**
	 * A write sent with the lock's release whose call fails - its library
	 * gone meanwhile (a FUNCTION FLUSH, a restart) - goes again through
	 * call(), which reloads the library: the value lands all the same
	 */
	public function aFailedWriteSentWithTheReleaseGoesAgainThroughCall(): bool
	{
		$probe = $this->probe();
		$key = 'p2_' . bin2hex(random_bytes(4));
		$library = $probe->getFunctions()
			->functionsPrefix('cache_versioned');
		
		try
		{
			$value = $probe->get($key, function() use ($probe, $library): string
			{
				// the library goes while the value is computed
				$probe->getClient()->function('delete', $library);
				
				return 'computed';
			}, 60);
			
			return $value === 'computed'
				&& count($probe->sentTogether) === 1
				&& $this->store->get($key, queue: false) === 'computed';
		}
		finally
		{
			$probe->delete($key);
		}
	}
	
	/**
	 * A look made with the lock that the rules cannot judge - none held yet,
	 * the stream read fails - is a miss, as fetch()'s read is: the lock stays
	 * this process's, the value is computed under it and the lock goes with
	 * the write, not at its TTL (the waiters would stall until then)
	 */
	public function aLookTheRulesCannotJudgeKeepsTheLock(): bool
	{
		$probe = $this->probe(['rules_shared_cache' => false]);
		$key = 'p2_' . bin2hex(random_bytes(4));
		$lockKey = $probe->getMemoLock()
			->getPrefixer()
			->prefix(MemoLock::TYPE_LOCK, $probe->prefix($key, $probe->getType()));
		$failure = new RedisException('the rules stream cannot be read');
		$thrown = 0;
		// an item for the look to judge: its verdict needs the rules
		$probe->beforeLock = function() use ($key): void
		{
			$this->store->set($key, 'landed');
		};
		$probe->beforeRuleFetch = function() use ($failure, &$thrown): void
		{
			if($thrown++ === 0)
			{
				throw $failure;
			}
		};
		
		try
		{
			$value = $probe->get($key, fn(): string => 'computed', 60, queueLockTtlMs: 30000);
			
			return $thrown > 0
				&& $value === 'computed'
				&& $probe->lookedWithLock === 1
				&& in_array($failure, array_column($probe->logged, 0), true)
				&& $probe->getClient()->exists($lockKey) === 0;
		}
		finally
		{
			$probe->getClient()->del($lockKey);
			$probe->delete($key);
		}
	}
	
	/**
	 * An 'all' rule naming one tag means what an 'any' rule on that tag means:
	 * it is held per tag (compacted), not in the list every read walks
	 */
	public function aOneTagAllRuleIsHeldAsAnAnyRule(): bool
	{
		$rules = new Rules(3600000);
		$rules->absorb([
			'1000-0' => ['mode' => 'all', 'tags' => 'tag1', 'first' => '1'],
			'2000-0' => ['mode' => 'all', 'tags' => 'tag1', 'first' => '0'],
			'3000-0' => ['mode' => 'all', 'tags' => 'tag1,tag2', 'first' => '0'],
		]);
		$held = $rules->toArray();
		
		return count($held['all']) === 1
			&& ($held['tags']['tag1'] ?? null) === [2000, 0]
			&& $rules->isStale(['tag1'], '1500-0') === true
			&& $rules->isStale(['tag2'], '1500-0') === false
			&& $rules->isStale(['tag1', 'tag2'], '2500-0') === true
			&& $rules->isStale(['tag1'], '2500-0') === false;
	}
	
	/**
	 * Past its cap the set keeps its newest rules and a floor in place of the
	 * rest - a clear at the newest rule it dropped: an item a dropped rule
	 * invalidated stays stale, an item stamped at or after the floor keeps
	 * its per-tag verdicts, one stamped before it reads stale (a miss, never
	 * a stale hit); the floor crosses requests in the shared shape; 0 caps
	 * nothing
	 */
	public function aRuleSetPastItsCapKeepsItsNewestRulesAndAFloor(): bool
	{
		$entries = [];
		for($i = 0; $i < 15; $i++)
		{
			$entries[(1000 + $i) . '-0'] = ['mode' => 'any', 'tags' => 't' . $i, 'first' => '0'];
		}
		$rules = (new Rules(3600000, 10))->absorb($entries);
		$held = $rules->toArray();
		$shared = Rules::fromArray($held, 3600000, 10);
		// a set taken over from another worker keeps the bound as rules arrive
		$more = ['1014-0' => $entries['1014-0']];
		for($i = 15; $i < 20; $i++)
		{
			$more[(1000 + $i) . '-0'] = ['mode' => 'any', 'tags' => 't' . $i, 'first' => '0'];
		}
		$grown = Rules::fromArray($held, 3600000, 10)?->absorb($more);
		$unbounded = (new Rules(3600000, 0))->absorb($entries);
		
		return count($held['tags']) <= 10
			// t0's rule (1000) invalidated an item stamped before it: still stale
			&& $rules->isStale(['t0'], '999-0') === true
			// stamped after the floor: the per-tag verdicts stand
			&& $rules->isStale(['t14'], '1012-0') === true
			&& $rules->isStale(['t0'], '1012-0') === false
			// stamped at the floor (the newest rule dropped, 1005): fresh; before it: stale
			&& $rules->isStale(['other'], '1005-0') === false
			&& $rules->isStale(['other'], '1004-0') === true
			&& $shared !== null
			&& $shared->isStale(['other'], '1004-0') === true
			&& $shared->isStale(['t14'], '1012-0') === true
			&& count($grown?->toArray()['tags'] ?? []) <= 10
			&& $grown?->isStale(['t19'], '1018-0') === true
			&& count($unbounded->toArray()['tags']) === 15;
	}
	
	/**
	 * rules_max_tags caps the set a store holds: an item a rule invalidated
	 * stays stale once that rule went with the cap, a value written after
	 * the floor is a hit
	 */
	public function rulesMaxTagsCapsTheHeldSet(): bool
	{
		$options = ['rules_max_tags' => 5, 'rules_cache_ms' => 0];
		$probe = $this->probe($options);
		$key = 'cap_' . bin2hex(random_bytes(4));
		$fresh = 'cap_fresh_' . bin2hex(random_bytes(4));
		$tag = 'cap-' . bin2hex(random_bytes(4));
		
		try
		{
			$probe->set($key, 'value', 60, [$tag]);
			$probe->invalidateTags([$tag]);
			for($i = 0; $i < 10; $i++)
			{
				$probe->invalidateTags([$tag . '-' . $i]);
			}
			$probe->set($fresh, 'value', 60, [$tag]);
			
			return count($probe->rules()->toArray()['tags']) <= 5
				&& $this->probe($options)->get($key, queue: false) === null
				&& $this->probe($options)->get($fresh, queue: false) === 'value';
		}
		finally
		{
			$probe->delete($key);
			$probe->delete($fresh);
		}
	}
	
	/**
	 * A set taken over from another worker of the server keeps the store's
	 * bound as rules arrive: rules_max_tags reaches what SharedRules loads
	 */
	public function anAdoptedRuleSetKeepsTheStoresBound(): bool
	{
		$this->requireSharedRules();
		
		$options = ['rules_max_tags' => 5];
		$tag = 'adopt-' . bin2hex(random_bytes(4));
		$first = $this->probe($options);
		$first->invalidateTags([$tag . '-0']);
		// shares the set it holds
		$first->rules();
		$second = $this->probe($options);
		// takes it over
		$second->rules();
		$adopted = $second->fetchedFrom === [];
		for($i = 1; $i < 12; $i++)
		{
			$this->probe($options)->invalidateTags([$tag . '-' . $i]);
		}
		// behind this server's invalidations: a delta into the set it took over
		$held = $second->rules()->toArray();
		
		return $adopted
			&& count($second->fetchedFrom) === 1
			&& count($held['tags']) <= 5;
	}
	
	/**
	 * A cold load reads the stream in batches, each from the last id held:
	 * never more than a batch in memory beside the set, every rule held at
	 * the end
	 */
	public function aColdLoadReadsTheStreamInBatches(): bool
	{
		$options = ['rules_cache_ms' => 0, 'rules_shared_cache' => false];
		$writer = $this->probe($options);
		$key = 'batch_' . bin2hex(random_bytes(4));
		$tag = 'batch-' . bin2hex(random_bytes(4));
		
		try
		{
			$writer->set($key, 'value', 60, [$tag . '-24']);
			for($i = 0; $i < 25; $i++)
			{
				$writer->invalidateTags([$tag . '-' . $i]);
			}
			$cold = $this->probe($options)
				->batchRulesBy(10);
			$read = $cold->get($key, queue: false);
			
			return count($cold->fetchedFrom) >= 3
				&& $cold->fetchedFrom[0] === Rules::NONE
				&& $cold->fetchedFrom[1] !== Rules::NONE
				&& $cold->rules()->last() === $writer->rules()->last()
				&& $read === null;
		}
		finally
		{
			$writer->delete($key);
		}
	}
	
	/**
	 * After the stream was lost with nothing appended since, the refresh that
	 * finds it gone shares the reset set: the workers stop adopting the lost
	 * one (and refreshing from it on every request)
	 */
	public function sharedRulesFollowALostStream(): bool
	{
		$this->requireSharedRules();
		
		$first = $this->probe(['rules_cache_ms' => 100]);
		$first->set('item1', 'test', tags: ['tag1']);
		$first->invalidateTags(['tag2']);
		// shares a set holding the rule
		$first->get('item1', queue: false);
		
		$this->store->getClient()
			->del($first->getRulesKey());
		usleep(150_000);
		
		$second = $this->probe(['rules_cache_ms' => 100]);
		$second->get('item1', queue: false);
		$shared = $second->sharedRules()
			?->load(3600000);
		
		try
		{
			return $shared !== null
				&& $shared[0]->last() === Rules::NONE;
		}
		finally
		{
			$first->delete('item1');
		}
	}
	
	/**
	 * Rules older than the longest possible item lifetime can match nothing
	 * alive and are dropped from the held set
	 */
	public function rulesDropWhatTheRetentionOutlives(): bool
	{
		$rules = new Rules(1000);
		$rules->absorb([
			'1000-0' => ['mode' => 'any', 'tags' => 'old', 'first' => '1'],
			'1500-0' => ['mode' => 'all', 'tags' => 'a,b', 'first' => '0'],
			'3000-0' => ['mode' => 'any', 'tags' => 'new', 'first' => '0'],
		]);
		
		$state = $rules->toArray();
		
		// the floor is 3000 - 1000: "old" and the "all" rule are behind it
		return $state['tags'] === ['new' => [3000, 0]]
			&& $state['all'] === []
			&& $rules->last() === '3000-0'
			&& $rules->isStale(['new'], '2000-0') === true
			&& $rules->isStale(['new'], '3000-0') === false;
	}
	
	/**
	 * A hard rule (invalidateTags(hard: true)) answers no soft time - and a
	 * soft rule after it does not soften it for the items written before it,
	 * only for those written between the two
	 */
	public function aHardRuleStaysHardUnderASoftOneForOlderItems(): bool
	{
		$rules = new Rules(3600000);
		$rules->absorb([
			'1000-0' => ['mode' => 'any', 'tags' => 'tag1', 'first' => '1', 'hard' => '1'],
			'2000-0' => ['mode' => 'any', 'tags' => 'tag1', 'first' => '0'],
		]);
		
		return $rules->invalidatedAt(['tag1'], '0-0') === null
			&& $rules->invalidatedAt(['tag1'], '1500-0') === 2000
			&& $rules->isStale(['tag1'], '0-0') === true;
	}
	
	/**
	 * A hard 'all' rule is hard for the items carrying all of its tags
	 */
	public function aHardAllRuleIsHard(): bool
	{
		$rules = new Rules(3600000);
		$rules->absorb([
			'1000-0' => ['mode' => 'all', 'tags' => 'a,b', 'first' => '1', 'hard' => '1'],
			'2000-0' => ['mode' => 'all', 'tags' => 'c,d', 'first' => '0'],
		]);
		
		return $rules->invalidatedAt(['a', 'b'], '0-0') === null
			&& $rules->invalidatedAt(['c', 'd'], '0-0') === 2000;
	}
	
	/**
	 * The hard rules cross requests with the set (SharedRules), and a set
	 * shared before they were kept still loads - with none
	 */
	public function hardRulesCrossTheSharedRules(): bool
	{
		$rules = new Rules(3600000);
		$rules->absorb([
			'1000-0' => ['mode' => 'any', 'tags' => 'tag1', 'first' => '1', 'hard' => '1'],
			'2000-0' => ['mode' => 'any', 'tags' => 'tag1', 'first' => '0'],
		]);
		
		$shared = Rules::fromArray($rules->toArray(), 3600000);
		$before = $rules->toArray();
		unset($before['hard']);
		$older = Rules::fromArray($before, 3600000);
		
		return $shared instanceof Rules
			&& $shared->invalidatedAt(['tag1'], '0-0') === null
			&& $older instanceof Rules
			&& $older->invalidatedAt(['tag1'], '0-0') === 2000;
	}
	
	/**
	 * The retention drops a hard rule with the rest - it cannot match
	 * anything alive any more
	 */
	public function theRetentionDropsHardRulesToo(): bool
	{
		$rules = new Rules(1000);
		$rules->absorb([
			'1000-0' => ['mode' => 'any', 'tags' => 'old', 'first' => '1', 'hard' => '1'],
			'3000-0' => ['mode' => 'any', 'tags' => 'new', 'first' => '0', 'hard' => '1'],
		]);
		
		$state = $rules->toArray();
		
		return $state['hard'] === ['new' => [3000, 0]];
	}
	
	/**
	 * A range that does not start at the last id held means the stream no
	 * longer holds it: what came back is the whole truth, and an item stamped
	 * on a rule the stream has lost is stale whatever its tags
	 */
	public function rulesReplaceWhatTheStreamNoLongerHolds(): bool
	{
		$rules = new Rules(3600000);
		$rules->absorb([
			'1000-0' => ['mode' => 'any', 'tags' => 'tag1', 'first' => '1'],
			'2000-0' => ['mode' => 'any', 'tags' => 'tag2', 'first' => '0'],
		]);
		
		// the incremental case first: the range starts at the id held
		$rules->absorb([
			'2000-0' => ['mode' => 'any', 'tags' => 'tag2', 'first' => '0'],
			'3000-0' => ['mode' => 'any', 'tags' => 'tag3', 'first' => '0'],
		]);
		$incremental = $rules->toArray();
		
		// then a rebuilt stream: nothing of what was held is in it
		$rules->absorb([
			'5000-0' => ['mode' => 'any', 'tags' => 'tag4', 'first' => '1'],
		]);
		$rebuilt = $rules->toArray();
		
		return count($incremental['tags']) === 3
			&& $incremental['last'] === '3000-0'
			&& $rebuilt['tags'] === ['tag4' => [5000, 0]]
			&& $rebuilt['last'] === '5000-0'
			&& $rules->isStale(['unrelated'], '2000-0') === true
			&& $rules->isStale(['unrelated'], '0-0') === false;
	}
	
	/**
	 * A refresh fetches only what was appended since the last id held, not
	 * the whole stream again
	 */
	public function rulesAreFetchedIncrementally(): bool
	{
		$store = $this->probe();
		
		$store->invalidateTags(['tag1']);
		$store->set(self::KEY_ITEM, 'test', tags: ['tag2']);
		// the first read loads the stream from the beginning
		$store->get(self::KEY_ITEM, queue: false);
		
		$last = $store->rules()
			->last();
		
		// a rule of our own: the next read fetches from the id held
		$store->invalidateTags(['tag3']);
		$store->get(self::KEY_ITEM, queue: false);
		
		try
		{
			return $last !== Rules::NONE
				&& $store->fetchedFrom === [Rules::NONE, $last]
				&& $store->rules()->last() !== $last;
		}
		finally
		{
			$store->delete(self::KEY_ITEM);
		}
	}
	
	/**
	 * Many rules on one tag compact to the newest one, with the verdicts
	 * unchanged: an item written before the burst is stale, one written
	 * after it is fresh
	 */
	public function rulesCompactToTheNewestPerTag(): bool
	{
		$store = $this->probe();
		
		$store->set('item1', 'test', tags: ['tag1']);
		
		for($i = 0; $i < 50; $i++)
		{
			$store->invalidateTags(['tag1']);
		}
		
		$store->set('item2', 'test', tags: ['tag1']);
		
		$item1 = $store->get('item1', queue: false);
		$item2 = $store->get('item2', queue: false);
		$state = $store->rules()
			->toArray();
		
		try
		{
			return $item1 === null
				&& $item2 === 'test'
				&& count($state['tags']) === 1
				&& isset($state['tags']['tag1'])
				&& $state['all'] === [];
		}
		finally
		{
			$store->delete('item1');
			$store->delete('item2');
		}
	}
	
	/**
	 * An "all" rule cannot be compacted per tag and is kept whole
	 */
	public function allRulesAreKeptWhole(): bool
	{
		$store = $this->probe();
		
		$store->set('item1', 'test', tags: ['tag1', 'tag2']);
		$store->set('item2', 'test', tags: ['tag1']);
		$store->invalidateTags(['tag1', 'tag2'], Store::MATCHING_ALL);
		
		$item1 = $store->get('item1', queue: false);
		$item2 = $store->get('item2', queue: false);
		$state = $store->rules()
			->toArray();
		
		try
		{
			return $item1 === null
				&& $item2 === 'test'
				&& $state['tags'] === []
				&& count($state['all']) === 1
				&& $state['all'][0][2] === ['tag1', 'tag2'];
		}
		finally
		{
			$store->delete('item1');
			$store->delete('item2');
		}
	}
	
	/**
	 * A clear says everything an older rule could say: the held set keeps the
	 * clear alone, and the verdicts still hold
	 */
	public function clearSubsumesOlderRules(): bool
	{
		$store = $this->probe();
		
		for($i = 1; $i <= 10; $i++)
		{
			$store->invalidateTags(['tag' . $i]);
		}
		$store->invalidateTags(['tag1', 'tag2'], Store::MATCHING_ALL);
		
		$store->set('item1', 'test', tags: ['tag1']);
		// load the rules before the clear, so the clear reaches a held set
		$store->get('item1', queue: false);
		
		$store->clear();
		$store->set('item2', 'test', tags: ['tag1']);
		
		$item1 = $store->get('item1', queue: false);
		$item2 = $store->get('item2', queue: false);
		$state = $store->rules()
			->toArray();
		
		try
		{
			return $item1 === null
				&& $item2 === 'test'
				&& $state['tags'] === []
				&& count($state['all']) === 1
				&& $state['all'][0][2] === [];
		}
		finally
		{
			$store->delete('item1');
			$store->delete('item2');
		}
	}
	
	/**
	 * A second instance - the next request, or another worker - adopts the
	 * shared set instead of loading the stream: no XRANGE, same verdict
	 */
	public function sharedRulesServeASecondInstanceWithoutRedis(): bool
	{
		$this->requireSharedRules();
		
		$first = $this->probe();
		$first->set('item1', 'test', tags: ['tag1']);
		$first->set('item2', 'test', tags: ['tag2']);
		$first->invalidateTags(['tag1']);
		// loads the stream, and leaves the set behind for the others
		$first->get('item2', queue: false);
		
		$second = $this->probe();
		$item1 = $second->get('item1', queue: false);
		
		try
		{
			return $item1 === null
				&& $second->fetchedFrom === [];
		}
		finally
		{
			$first->delete('item1');
			$first->delete('item2');
		}
	}
	
	/**
	 * A fresh shared set must not hide an invalidation this very process just
	 * made: the read after it fetches the delta, and shares the result
	 */
	public function sharedRulesDoNotHideOwnInvalidation(): bool
	{
		$this->requireSharedRules();
		
		$first = $this->probe();
		$first->set('item1', 'test', tags: ['tag1']);
		$first->set('item2', 'test', tags: ['tag2']);
		$first->set('item3', 'test', tags: ['tag1']);
		// the shared set is stored here, fresh, without the rule below
		$first->get('item2', queue: false);
		
		$first->invalidateTags(['tag1']);
		$item1 = $first->get('item1', queue: false);
		
		// a second instance adopts what the first shared back
		$second = $this->probe();
		$item3 = $second->get('item3', queue: false);
		
		try
		{
			return $item1 === null
				&& count($first->fetchedFrom) === 2
				&& $item3 === null
				&& $second->fetchedFrom === [];
		}
		finally
		{
			$first->delete('item1');
			$first->delete('item2');
			$first->delete('item3');
		}
	}
	
	/**
	 * "rules_shared_cache: no" keeps the set in the instance alone
	 */
	public function sharedRulesCanBeSwitchedOff(): bool
	{
		$this->requireSharedRules();
		
		$first = $this->probe(['rules_shared_cache' => false]);
		$first->set(self::KEY_ITEM, 'test', tags: ['tag1']);
		$first->invalidateTags(['tag2']);
		$first->get(self::KEY_ITEM, queue: false);
		
		// nothing was shared: an instance with sharing on has to load
		$second = $this->probe();
		$second->get(self::KEY_ITEM, queue: false);
		
		try
		{
			return $first->sharedRules() === null
				&& $second->sharedRules() !== null
				&& $second->fetchedFrom === [Rules::NONE];
		}
		finally
		{
			$first->delete(self::KEY_ITEM);
		}
	}
	
	/**
	 * An invalidation is seen by the next request on this server even when the
	 * request that made it read nothing afterwards: a set fetched before it is
	 * not fresh, the next read fetches the delta
	 */
	public function sharedRulesSeeThisServersInvalidationOnTheNextRequest(): bool
	{
		$this->requireSharedRules();
		
		$first = $this->probe();
		$first->set('item1', 'test', tags: ['tag1']);
		$first->set('item2', 'test', tags: ['tag2']);
		// the shared set is stored here, fresh, without the rule below
		$first->get('item2', queue: false);
		$last = $first->rules()
			->last();
		
		// the request invalidates and ends - it reads nothing afterwards
		$first->invalidateTags(['tag1']);
		
		// the next request on this server, inside the shared set's freshness
		$second = $this->probe();
		$item1 = $second->get('item1', queue: false);
		
		try
		{
			return $item1 === null
				&& $second->fetchedFrom === [$last];
		}
		finally
		{
			$first->delete('item1');
			$first->delete('item2');
		}
	}
	
	/**
	 * A worker holding a fresh set sees an invalidation another process of
	 * this server made at once: the held set is behind it, one delta fetch
	 */
	public function aHeldSetIsBehindThisServersInvalidation(): bool
	{
		$this->requireSharedRules();
		
		$worker = $this->probe();
		$worker->set('item1', 'test', tags: ['tag1']);
		$before = $worker->get('item1', queue: false);
		$last = $worker->rules()
			->last();
		$fetches = count($worker->fetchedFrom);
		
		$this->probe()
			->invalidateTags(['tag1']);
		$after = $worker->get('item1', queue: false);
		
		try
		{
			return $before === 'test'
				&& $after === null
				&& count($worker->fetchedFrom) === $fetches + 1
				&& end($worker->fetchedFrom) === $last;
		}
		finally
		{
			$worker->delete('item1');
		}
	}
	
	/**
	 * A worker behind an invalidation made on this server does not leave the
	 * refresh to a leader in flight - that leader may have started before the
	 * rule: it fetches the delta itself
	 */
	public function aWorkerBehindThisServersInvalidationFetchesDespiteALeader(): bool
	{
		$this->requireSharedRules();
		
		$worker = $this->probe();
		$worker->set('item1', 'test', tags: ['tag1']);
		// holds a fresh set, and shares it
		$before = $worker->get('item1', queue: false);
		$last = $worker->rules()
			->last();
		
		$this->probe()
			->invalidateTags(['tag1']);
		// a leader is refreshing right now
		$flag = $worker->sharedRules();
		$flag->lead();
		
		try
		{
			$after = $worker->get('item1', queue: false);
			
			return $before === 'test'
				&& $after === null
				&& end($worker->fetchedFrom) === $last;
		}
		finally
		{
			$flag->release();
			$worker->delete('item1');
		}
	}
	
	/**
	 * A cold worker waiting for the elected loader does not take a set the
	 * leader fetched before an invalidation made on this server - it waits
	 * the budget out, then fetches the delta from it
	 */
	public function aColdWaiterDoesNotTakeASetFetchedBeforeThisServersInvalidation(): bool
	{
		$this->requireSharedRules();
		
		$first = $this->probe();
		$first->set('item1', 'test', tags: ['tag1']);
		// the leader's load, begun before the invalidation below
		$beganMs = microtime(true) * 1000;
		$early = $first->rules();
		$last = $early->last();
		$first->invalidateTags(['tag1']);
		
		// nothing shared yet, and the flag says a loader is at work
		$flag = $first->sharedRules();
		$flag->forget();
		$flag->lead();
		
		$second = $this->probe();
		$adoptions = 0;
		$second->beforeAdopt = function(SharedRules $shared) use (&$adoptions, $early, $beganMs): void
		{
			// the leader shares its set while the second one waits
			if($adoptions++ === 1)
			{
				$shared->store($early, $beganMs);
			}
		};
		$item1 = $second->get('item1', queue: false);
		
		try
		{
			return $adoptions > 2
				&& $item1 === null
				&& $second->fetchedFrom === [$last];
		}
		finally
		{
			$flag->release();
			$first->delete('item1');
		}
	}
	
	/**
	 * A rule appended by another process - another worker, another server -
	 * reaches a worker that adopted a fresh shared set within rules_cache_ms:
	 * the refresh after the window is a delta fetch from the id held, and the
	 * verdict flips
	 */
	public function sharedRulesFollowARuleAppendedElsewhere(): bool
	{
		$this->requireSharedRules();
		
		$first = $this->probe(['rules_cache_ms' => 100]);
		$first->set('item1', 'test', tags: ['tag1']);
		$first->set('item2', 'test', tags: ['tag2']);
		$first->invalidateTags(['tag2']);
		// loads the stream and shares the set, fresh
		$first->get('item1', queue: false);
		
		$last = $first->rules()
			->last();
		
		// another process invalidates tag1: straight into the stream, the
		// way cache_versioned_invalidate writes it
		$this->store->getClient()
			->xAdd($first->getRulesKey(), '*', ['mode' => 'any', 'tags' => 'tag1', 'first' => '0']);
		
		$second = $this->probe(['rules_cache_ms' => 100]);
		// within the window: the adopted set does not know the rule yet
		$within = $second->get('item1', queue: false);
		usleep(150_000);
		// after it: one delta fetch from the id held, and the item is stale
		$after = $second->get('item1', queue: false);
		
		try
		{
			return $within === 'test'
				&& $after === null
				&& $second->fetchedFrom === [$last];
		}
		finally
		{
			$first->delete('item1');
			$first->delete('item2');
		}
	}
	
	/**
	 * A physical wipe drops the shared set even when the wiping instance does
	 * not read it: the stream it described is gone for every worker, and an
	 * instance adopting it would stamp and judge against ids that no longer
	 * exist
	 */
	public function clearPhysicalDropsTheSharedRulesWhateverTheOption(): bool
	{
		$this->requireSharedRules();
		
		$first = $this->probe();
		$first->invalidateTags(['tag1']);
		$first->set(self::KEY_ITEM, 'test', tags: ['tag2']);
		// shares the set
		$first->get(self::KEY_ITEM, queue: false);
		
		// a refresh flag left behind goes with it
		$flag = $first->sharedRules();
		$flag->lead();
		
		$wiper = $this->probe(['rules_shared_cache' => false]);
		$wiper->clearPhysical();
		
		$second = $this->probe();
		$second->set(self::KEY_ITEM, 'test', tags: ['tag2']);
		$result = $second->get(self::KEY_ITEM, queue: false);
		
		try
		{
			// nothing to adopt: the stream is loaded from the beginning
			return $result === 'test'
				&& $second->fetchedFrom === [Rules::NONE]
				&& $flag->isRefreshing() === false;
		}
		finally
		{
			$second->delete(self::KEY_ITEM);
		}
	}
	
	/**
	 * When several workers cross the window together, the one that wins the
	 * election refreshes; the others keep the set they hold for that read
	 * instead of fetching the same delta, and are exact again a refresh later
	 */
	public function staleSharedRulesAreRefreshedByOneWorker(): bool
	{
		$this->requireSharedRules();
		
		$first = $this->probe(['rules_cache_ms' => 50]);
		$first->set('item1', 'test', tags: ['tag1']);
		$first->set('item2', 'test', tags: ['tag2']);
		$first->invalidateTags(['tag2']);
		// loads and shares the set
		$first->get('item1', queue: false);
		
		$last = $first->rules()
			->last();
		
		// another server invalidates tag1, and the window passes
		$this->store->getClient()
			->xAdd($first->getRulesKey(), '*', ['mode' => 'any', 'tags' => 'tag1', 'first' => '0']);
		usleep(60_000);
		
		// a worker elsewhere on this server is refreshing right now
		$flag = $first->sharedRules();
		$flag->lead();
		
		$second = $this->probe(['rules_cache_ms' => 50]);
		// keeps the stale set for this read: no fetch, the old verdict
		$meanwhile = $second->get('item1', queue: false);
		$fetchedMeanwhile = $second->fetchedFrom;
		
		$flag->release();
		// now it leads: one delta fetch, the new verdict, the flag handed back
		$after = $second->get('item1', queue: false);
		
		try
		{
			return $meanwhile === 'test'
				&& $fetchedMeanwhile === []
				&& $after === null
				&& $second->fetchedFrom === [$last]
				&& $flag->isRefreshing() === false;
		}
		finally
		{
			$first->delete('item1');
			$first->delete('item2');
		}
	}
	
	/**
	 * A worker holding nothing, on a server whose APCu holds nothing either,
	 * waits for the elected loader rather than load the stream as well - for
	 * a bounded time, after which it loads on its own
	 */
	public function coldWorkersWaitForTheElectedLoader(): bool
	{
		$this->requireSharedRules();
		
		$first = $this->probe();
		$first->set(self::KEY_ITEM, 'test', tags: ['tag1']);
		$first->invalidateTags(['tag2']);
		
		// nothing shared, and the flag says a loader is at work
		$flag = $first->sharedRules();
		$flag->forget();
		$flag->lead();
		
		$second = $this->probe();
		$began = microtime(true);
		$result = $second->get(self::KEY_ITEM, queue: false);
		$waited = microtime(true) - $began;
		
		try
		{
			// waited the budget out, then loaded on its own - correctly
			return $result === 'test'
				&& $second->fetchedFrom === [Rules::NONE]
				&& $waited >= Store::COLD_WAIT_MS / 1000 * 0.8;
		}
		finally
		{
			$flag->release();
			$first->delete(self::KEY_ITEM);
		}
	}
	
	/**
	 * Exact reads (rules_cache_ms = 0) elect nobody: every read refreshes,
	 * whatever the flag says
	 */
	public function exactReadsElectNobody(): bool
	{
		$this->requireSharedRules();
		
		$first = $this->probe(['rules_cache_ms' => 0]);
		$first->set(self::KEY_ITEM, 'test', tags: ['tag1']);
		$first->invalidateTags(['tag2']);
		$first->get(self::KEY_ITEM, queue: false);
		$fetches = count($first->fetchedFrom);
		
		// the flag says another worker is refreshing: an exact read fetches anyway
		$flag = $first->sharedRules();
		$flag->lead();
		$first->get(self::KEY_ITEM, queue: false);
		
		try
		{
			return count($first->fetchedFrom) === $fetches + 1;
		}
		finally
		{
			$flag->release();
			$first->delete(self::KEY_ITEM);
		}
	}
	
	/**
	 * The shared entry is keyed by the connection too: a second Redis behind
	 * the same prefix on the same server never reads these rules
	 */
	public function sharedRulesAreKeyedByConnection(): bool
	{
		$a = new SharedRules('tests:core:rules', 'host-a:6379:0');
		$b = new SharedRules('tests:core:rules', 'host-b:6379:0');
		$c = new SharedRules('tests:core:rules', 'host-a:6379:0');
		
		return $a->getKey() !== $b->getKey()
			&& $a->getKey() === $c->getKey();
	}
	
	/**
	 * The versioned store with its rule plumbing exposed
	 */
	protected function probe(
		array $storeOptions = [],
	): RedisVersionedProbe
	{
		/** @var RedisVersionedProbe $probe */
		$probe = $this->getStore(RedisVersionedProbe::class, $storeOptions);
		
		return $probe;
	}
	
	protected function requireSharedRules(): void
	{
		if(SharedRules::isAvailable() === false)
		{
			throw new SkipException('APCu is not enabled for this SAPI (apc.enable_cli).');
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
	 * Called by the runner after each test method
	 */
	#[Internal]
	#[Override]
	public function finalize(): void
	{
		$this->store->clearPhysical();
		// a fresh store for the next rule: this one remembers its misses
		// (the invalidation guard - see KeyValue::rememberMiss())
		$this->store = $this->getStore(Store::class);
	}
	
	/**
	 * Called by the runner after all test methods have been invoked
	 */
	#[Internal]
	#[Override]
	public function deconstruct(): void
	{
		$this->store->getConnection()
			->disconnect();
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
		return $this->getStore(Store::class, $storeOptions + [
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
	 * item's remaining lifetime: past the window the epoch guards no miss (an
	 * older one goes unguarded), so an epoch-only hash kept for up to the
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
	
	/**
	 * Every store with tags invalidates them through one declared signature
	 * (KeyValue\Tags::invalidateTags()): a caller holding "a store with tags"
	 * - the persistent tier, whichever store it is configured to - can
	 * invalidate without knowing the class
	 */
	public function everyTaggedStoreDeclaresInvalidateTags(): bool
	{
		return method_exists(Tags::class, 'invalidateTags')
			&& (new ReflectionMethod(Tags::class, 'invalidateTags'))->isAbstract()
			&& is_subclass_of(Store::class, Tags::class);
	}
	
	/**
	 * The versioned store carries none of the tag-index stores' machinery -
	 * the per-tag stamps, the server-time miss stamp, the soft mark: its rules
	 * do all of that (KeyValue\TagIndex, which Store\Redis and Redisearch
	 * extend)
	 */
	public function theVersionedStoreCarriesNoTagIndexMember(): bool
	{
		return is_subclass_of(RedisStore::class, TagIndex::class)
			&& is_subclass_of(Redisearch::class, TagIndex::class)
			&& is_subclass_of(Store::class, TagIndex::class) === false
			&& method_exists(Store::class, 'stamp') === false
			&& method_exists(Store::class, 'stampKey') === false
			&& method_exists(Store::class, 'serverTime') === false
			&& defined(Store::class . '::KEY_INVALIDATED') === false
			&& defined(Store::class . '::TYPE_INVALIDATED') === false;
	}
}
