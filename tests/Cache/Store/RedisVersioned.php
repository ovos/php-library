<?php
declare(strict_types=1);

namespace Tests\Cache\Store;

use Ovos\Cache\Computed;
use Ovos\Cache\MemoLock\Redis as MemoLock;
use Ovos\Cache\Store\KeyValue;
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
use Ovos\Test\Cache\Store\SharedRulesInFiles;
use Ovos\Test\Cache\Store\SharedRulesProbe;
use Ovos\Test\Cache\Store\TraitBatch;
use Ovos\Test\Cache\Store\TraitInvalidationGuard;
use Ovos\Test\Cache\Store\TraitStaleWhileRevalidate;
use Ovos\Test\Cache\Store\TraitCachePolicy;
use Ovos\Test\Cache\Store\TraitStoredStrings;
use Ovos\Test\Cache\Store\TraitRedis;
use Ovos\Test\Exception\SkipException;
use Override;
use Closure;
use RedisException;
use ReflectionMethod;

use function array_column;
use function array_fill;
use function array_fill_keys;
use function array_intersect;
use function array_key_first;
use function array_keys;
use function bin2hex;
use function count;
use function defined;
use function end;
use function file_get_contents;
use function floor;
use function gc_collect_cycles;
use function glob;
use function in_array;
use function is_file;
use function is_resource;
use function is_string;
use function is_subclass_of;
use function json_decode;
use function max;
use function method_exists;
use function microtime;
use function mkdir;
use function proc_close;
use function proc_open;
use function random_bytes;
use function rmdir;
use function sprintf;
use function str_contains;
use function str_ends_with;
use function str_repeat;
use function sys_get_temp_dir;
use function unlink;
use function usleep;

use const DIRECTORY_SEPARATOR;
use const PHP_BINARY;

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
	use TraitBatch;
	
	public const string KEY_ITEM = 'item';
	
	/**
	 * rules_cache_ms of the reload rules' workers
	 * Unit: milliseconds
	 */
	protected const int RELOAD_CACHE_MS = 100;
	
	/**
	 * How long before the moment they read the workers of the process rules
	 * are started: time to boot the application and connect
	 * Unit: seconds
	 */
	protected const float PROCESS_WARM_UP_S = 3.0;
	
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
	 * A rule from elsewhere is fetched from the id held: the first read loads
	 * the stream from the beginning, the first one past rules_cache_ms only
	 * what was appended since
	 */
	public function rulesAreFetchedIncrementally(): bool
	{
		$store = $this->probe(['rules_cache_ms' => 50]);
		
		$store->invalidateTags(['tag1']);
		$store->set(self::KEY_ITEM, 'test', tags: ['tag2']);
		// the first read loads the stream from the beginning
		$store->get(self::KEY_ITEM, queue: false);
		
		$last = $store->rules()
			->last();
		
		// a rule from elsewhere, and the window passes: the next read fetches
		// from the id held
		$store->getClient()
			->xAdd($store->getRulesKey(), '*', ['mode' => 'any', 'tags' => 'tag3', 'first' => '0']);
		usleep(60000);
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
				&& $flag->reloadStartedAt() === null;
		}
		finally
		{
			$second->delete(self::KEY_ITEM);
		}
	}
	
	/**
	 * When several workers cross the window together, the one that takes the
	 * flag reloads; the others are judged by the set they hold - no older than
	 * rules_cache_ms + SLOW_RELOAD_MS - instead of fetching the same delta,
	 * and are exact again once it is free
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
		usleep(60000);
		
		// a worker elsewhere on this server is reloading right now
		$flag = $first->sharedRules();
		$flag->lead();
		
		$second = $this->probe(['rules_cache_ms' => 50]);
		// judged by the set it adopted: no fetch, the old verdict
		$meanwhile = $second->get('item1', queue: false);
		$fetchedMeanwhile = $second->fetchedFrom;
		
		$flag->release();
		// now it reloads: one delta fetch, the new verdict, the flag let go
		$after = $second->get('item1', queue: false);
		
		try
		{
			return $meanwhile === 'test'
				&& $fetchedMeanwhile === []
				&& $after === null
				&& $second->fetchedFrom === [$last]
				&& $flag->reloadStartedAt() === null;
		}
		finally
		{
			$first->delete('item1');
			$first->delete('item2');
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
	 * RULE: a miss read with no copy of the rules at hand stores nothing. So a value computed while its tag
	 * was invalidated is never served.
	 */
	public function aMissTheRulesCannotJudgeIsNeverServedAfterATagInvalidation(): bool
	{
		$other = $this->guardStore();
		$key = $this->guardKey('unjudged-tag');
		$other->invalidateTags(['unrelated']);
		$other->set($key, 'old', 60, [self::GUARD_TAG]);
		$failing = true;
		$probe = $this->rulesFailingProbe($failing);
		
		$value = $probe->get($key, function() use ($other, &$failing): string
		{
			$other->invalidateTags([self::GUARD_TAG]);
			$failing = false;
			
			return 'computed before the invalidation';
		}, 60, [self::GUARD_TAG]);
		$read = $this->guardStore()->get($key, queue: false);
		$other->delete($key);
		
		return $value === 'computed before the invalidation'
			&& $read === null;
	}
	
	/**
	 * RULE: a miss read with no copy of the rules at hand stores nothing. The caller gets the computed value,
	 * and the item stays as it was, also when the rules can be read again by the time the value is computed
	 * (a valid item is not evicted).
	 */
	public function aMissTheRulesCannotJudgeStoresNothing(): bool
	{
		$other = $this->guardStore();
		$key = $this->guardKey('unjudged');
		$other->invalidateTags([self::GUARD_TAG]);
		$other->set($key, 'old', 60, [self::GUARD_TAG]);
		$mark = $other->getClient()
			->hGet($other->itemId($key), Store::KEY_MARK);
		$probe = $this->rulesFailingOnceProbe();
		
		$value = $probe->get($key, fn(): string => 'recomputed', 60, [self::GUARD_TAG], queue: false);
		$kept = $other->getClient()
			->hGet($other->itemId($key), Store::KEY_MARK);
		$read = $this->guardStore()->get($key, queue: false);
		$other->delete($key);
		
		return $value === 'recomputed'
			&& $kept === $mark
			&& $read === 'old';
	}
	
	/**
	 * RULE: a miss whose rule id could not be taken stores nothing. That happens when the rules cannot be
	 * read right before the computation, or at a write after peek() that never got a rule id. The value is
	 * never written with a rule id taken at write time, because that id would already cover the rules added
	 * while the value was computed.
	 */
	public function aMissWhoseStampCannotBeReadStoresNothing(): bool
	{
		$other = $this->guardStore();
		$key = $this->guardKey('unstamped');
		$peeked = $this->guardKey('unstamped-peek');
		$other->invalidateTags(['unrelated']);
		// tombstones: the misses need no rules, their stamps do
		$other->delete($key);
		$other->delete($peeked);
		$failing = true;
		$probe = $this->rulesFailingProbe($failing);
		
		$value = $probe->get($key, fn(): string => 'computed', 60, [self::GUARD_TAG], queue: false);
		$probe->peek($peeked);
		$written = $probe->set($peeked, 'written', 60, [self::GUARD_TAG]);
		$failing = false;
		$reader = $this->guardStore();
		$read = [
			$reader->get($key, queue: false),
			$reader->get($peeked, queue: false),
		];
		$other->delete($key);
		$other->delete($peeked);
		
		return $value === 'computed'
			&& $written === false
			&& $read === [null, null];
	}
	
	/**
	 * RULE: a set() after a peek() (a miss that never got a rule id, because nothing ran under stampMiss())
	 * is stored with the newest rule id of the copy held at the write, and is served.
	 */
	public function aSetAfterAPeekIsStampedWithTheWatermarkHeld(): bool
	{
		$other = $this->guardStore();
		$key = $this->guardKey('peeked');
		$other->invalidateTags(['unrelated']);
		$other->delete($key);
		$probe = $this->probe(['rules_shared_cache' => false]);
		
		$probe->peek($key);
		$written = $probe->set($key, 'written', 60, [self::GUARD_TAG]);
		$read = $this->guardStore()->get($key, queue: false);
		$other->delete($key);
		
		return $written
			&& $read === 'written';
	}
	
	/**
	 * RULE: a batch read with no copy of the rules at hand stores nothing. Every value goes back to the
	 * caller, and every item stays as it was.
	 */
	public function aBatchTheRulesCannotJudgeStoresNothing(): bool
	{
		$other = $this->guardStore();
		$a = $this->guardKey('unjudged-a');
		$b = $this->guardKey('unjudged-b');
		$other->invalidateTags(['unrelated']);
		$other->set($a, 'old a', 60, [self::GUARD_TAG]);
		$other->set($b, 'old b', 60, [self::GUARD_TAG]);
		$probe = $this->rulesFailingOnceProbe();
		
		$values = $probe->getMany([$a, $b], fn(KeyValue $store, array $keys): array => [
			$a => 'new a',
			$b => 'new b',
		], 60, [self::GUARD_TAG], queue: false);
		$reader = $this->guardStore();
		$read = [
			$reader->get($a, queue: false),
			$reader->get($b, queue: false),
		];
		$other->delete($a);
		$other->delete($b);
		
		return $values === ['new a', 'new b']
			&& $read === ['old a', 'old b'];
	}
	
	/**
	 * RULE (probe): a value longer than the Compressor reads back is not cached. set() answers false, logs
	 * one line and sends one command: the delete of what the key held, never the value.
	 */
	public function anOversizeValueIsNotSentAndLoggedOnce(): bool
	{
		/** @var RedisVersionedProbe $probe */
		$probe = $this->roundTripStore(RedisVersionedProbe::class);
		$this->capCompressor($probe);
		$counter = $probe->getConnection()->counter();
		$key = $this->guardKey('oversize');
		// an older value under the key, the library loaded with it
		$probe->set($key, 'older', 60);
		$counter->resetCount();
		
		$written = $probe->set($key, str_repeat('x', 4096), 60);
		$sent = $counter->sent;
		$read = $this->guardStore()->get($key, queue: false);
		$probe->delete($key);
		
		return $written === false
			&& $sent === [['fcall']]
			&& count($probe->logged) === 1
			&& $read === null;
	}
	
	/**
	 * RULE: a forced refresh whose read had no copy of the rules at hand stores nothing, and deletes the
	 * value it was meant to replace, so that value is not served any longer.
	 */
	public function anUnjudgedForcedRefreshLeavesNoOldValue(): bool
	{
		$other = $this->guardStore();
		$key = $this->guardKey('unjudged-refresh');
		$other->invalidateTags(['unrelated']);
		$other->set($key, 'old', 60, [self::GUARD_TAG]);
		$probe = $this->rulesFailingOnceProbe();
		
		$value = $probe->get($key, fn(): string => 'refreshed', 60, [self::GUARD_TAG], refresh: true);
		$read = $this->guardStore()->get($key, queue: false);
		$other->delete($key);
		
		return $value === 'refreshed'
			&& $read === null;
	}
	
	/**
	 * RULE: a set() after a read with no copy of the rules at hand (a read-only get(), a peek()) stores
	 * nothing, and deletes the value the caller replaces.
	 */
	public function anUnjudgedSetAfterItsReadLeavesNoOldValue(): bool
	{
		$other = $this->guardStore();
		$read = $this->guardKey('unjudged-read');
		$peeked = $this->guardKey('unjudged-peek');
		$other->invalidateTags(['unrelated']);
		$other->set($read, 'old', 60, [self::GUARD_TAG]);
		$other->set($peeked, 'old', 60, [self::GUARD_TAG]);
		
		$reader = $this->rulesFailingOnceProbe();
		$missed = $reader->get($read, queue: false);
		$written = $reader->set($read, 'new', 60, [self::GUARD_TAG]);
		$peeker = $this->rulesFailingOnceProbe();
		$peek = $peeker->peek($peeked);
		$peekWritten = $peeker->set($peeked, 'new', 60, [self::GUARD_TAG]);
		$check = $this->guardStore();
		$after = [
			$check->get($read, queue: false),
			$check->get($peeked, queue: false),
		];
		$other->delete($read);
		$other->delete($peeked);
		
		return $missed === null
			&& $written === false
			&& $peek === null
			&& $peekWritten === false
			&& $after === [null, null];
	}
	
	/**
	 * RULE: a forced refresh of a batch whose read had no copy of the rules at hand stores nothing, and
	 * deletes the values it was meant to replace.
	 */
	public function anUnjudgedForcedBatchRefreshLeavesNoOldValues(): bool
	{
		$other = $this->guardStore();
		$a = $this->guardKey('unjudged-refresh-a');
		$b = $this->guardKey('unjudged-refresh-b');
		$other->invalidateTags(['unrelated']);
		$other->set($a, 'old a', 60, [self::GUARD_TAG]);
		$other->set($b, 'old b', 60, [self::GUARD_TAG]);
		$probe = $this->rulesFailingOnceProbe();
		
		$values = $probe->getMany([$a, $b], fn(KeyValue $store, array $keys): array => [
			$a => 'new a',
			$b => 'new b',
		], 60, [self::GUARD_TAG], refresh: true);
		$reader = $this->guardStore();
		$read = [
			$reader->get($a, queue: false),
			$reader->get($b, queue: false),
		];
		$other->delete($a);
		$other->delete($b);
		
		return $values === ['new a', 'new b']
			&& $read === [null, null];
	}
	
	/**
	 * RULE: when the Redis server's time cannot be read (TIME fails), nobody can tell whether a softly
	 * invalidated value is still inside its stale time. That read takes it for a miss, the item stays, and a
	 * reader that can read the time still serves it aged.
	 */
	public function aSoftVerdictWithoutTheServersTimeKeepsTheItem(): bool
	{
		$key = $this->guardKey('soft-time');
		$keep = static function(Closure $refresh): void
		{
		};
		$writer = $this->guardStore();
		$writer->get($key, fn(): string => 'old', 600, [self::GUARD_TAG], stale: 60, soft: true);
		$writer->invalidateTags([self::GUARD_TAG]);
		$recompute = fn(): Computed => new Computed('recomputed', save: false);
		
		$timeless = $this->probe([
			'rules_cache_ms' => 0,
			'rules_shared_cache' => false,
		]);
		$timeless->timeUnreadable = true;
		$timeless->setDeferrer($keep);
		$first = $timeless->get($key, $recompute, 600, [self::GUARD_TAG], stale: 60, soft: true);
		
		$second = $this->guardStore()
			->setDeferrer($keep)
			->get($key, $recompute, 600, [self::GUARD_TAG], stale: 60, soft: true);
		$kept = $writer->getClient()
			->hExists($writer->itemId($key), Store::KEY_DATA);
		$writer->delete($key);
		
		return $first === 'recomputed'
			&& $second === 'old'
			&& $kept === true;
	}
	
	/**
	 * RULE: the miss left by a soft value whose stale time could not be checked (no server time) still guards
	 * its write: if the key is deleted while the value is computed, the write is refused.
	 */
	public function aMissWithoutTheServersTimeStillGuardsItsWrite(): bool
	{
		$key = $this->guardKey('soft-time-guard');
		$writer = $this->guardStore();
		$writer->get($key, fn(): string => 'old', 600, [self::GUARD_TAG], stale: 60, soft: true);
		$writer->invalidateTags([self::GUARD_TAG]);
		
		$timeless = $this->probe([
			'rules_cache_ms' => 0,
			'rules_shared_cache' => false,
		]);
		$timeless->timeUnreadable = true;
		$value = $timeless->get($key, function() use ($writer, $key): string
		{
			$writer->delete($key);
			
			return 'computed before the delete';
		}, 600, [self::GUARD_TAG], stale: 60, soft: true, queue: false);
		$read = $this->guardStore()->get($key, queue: false);
		$writer->delete($key);
		
		return $value === 'computed before the delete'
			&& $read === null;
	}
	
	/**
	 * RULE: a reload that is running is not started again. A worker that reads while the reloader fetches
	 * uses its own copy. Once that fetch has failed (an outage), the next read uses its copy too. So a slow
	 * failure costs one fetch, however many reads follow.
	 */
	public function aReloadInFlightIsNotRepeated(): bool
	{
		$this->requireSharedRules();
		
		[$first, $group] = $this->copyAged(Store::SLOW_RELOAD_MS / 2);
		$attempts = [];
		$reads = [];
		$leader = $this->reloadProbe($group);
		$nested = $this->reloadProbe($group);
		$nested->beforeRuleFetch = $this->recordAttempt($attempts, 'nested');
		$leader->beforeRuleFetch = function() use ($nested, &$attempts, &$reads): void
		{
			$attempts[] = 'leader';
			// another worker reads while this attempt is in flight
			$reads['nested'] = $nested->get('item1', queue: false);
			usleep(50000);
			
			throw new RedisException('the rules node does not answer');
		};
		$reads['leader'] = $leader->get('item1', queue: false);
		$after = $this->reloadProbe($group);
		$after->beforeRuleFetch = $this->recordAttempt($attempts, 'after');
		$reads['after'] = $after->get('item1', queue: false);
		
		try
		{
			return $attempts === ['leader']
				&& $reads === ['nested' => 'test', 'leader' => 'test', 'after' => 'test']
				&& $first->sharedRules()->lastAttempt()[0];
		}
		finally
		{
			$this->reloadTearDown($first);
		}
	}
	
	/**
	 * RULE: while the rules cannot be read, a retry that runs longer than SLOW_RELOAD_MS is still the only
	 * one. The retrying worker holds the reload flag while it fetches. So a read after RETRY_MS has passed
	 * starts no second attempt, and uses the copy it holds.
	 */
	public function inAnOutageARetryLongerThanItsPeriodIsStillAlone(): bool
	{
		$this->requireSharedRules();
		
		[$first, $group] = $this->copyAged(5000);
		$shared = $first->sharedRules();
		// the last attempt ended more than the retry period ago
		$shared->outageSince(microtime(true) * 1000 - Store::RETRY_MS - 10);
		$attempts = [];
		$reads = [];
		$retrier = $this->reloadProbe($group);
		$nested = $this->reloadProbe($group);
		$nested->beforeRuleFetch = $this->recordAttempt($attempts, 'nested');
		$retrier->beforeRuleFetch = function() use ($nested, &$attempts, &$reads): void
		{
			$attempts[] = 'retrier';
			// still in flight past the retry period, and past SLOW_RELOAD_MS
			usleep((Store::SLOW_RELOAD_MS + 50) * 1000);
			$reads[] = $nested->get('item1', queue: false);
			
			throw new RedisException('the rules node does not answer');
		};
		$reads[] = $retrier->get('item1', queue: false);
		
		try
		{
			return $attempts === ['retrier']
				&& $reads === ['test', 'test'];
		}
		finally
		{
			$this->reloadTearDown($first);
		}
	}
	
	/**
	 * RULE: a physical wipe (clearPhysical()) ends an outage, together with the record of the failed attempt,
	 * because the stream that attempt was about is gone.
	 */
	public function aPhysicalClearEndsTheOutage(): bool
	{
		$this->requireSharedRules();
		
		$probe = $this->probe();
		$shared = $probe->sharedRules();
		$shared->outageSince(microtime(true) * 1000);
		$before = $shared->lastAttempt();
		
		$probe->clearPhysical();
		
		return $before[0]
			&& $shared->lastAttempt() === [false, null, null];
	}
	
	/**
	 * RULE: a plain write (no miss before it) by a worker with no copy of the rules, while another worker
	 * reloads them, has no rule id to be stored with. set() and setMany() answer false and delete the keys,
	 * so the values they were meant to replace are not served.
	 */
	public function aPlainWriteOfAColdWorkerDeletesTheKey(): bool
	{
		$this->requireSharedRules();
		
		[$group, $other] = $this->plainWriteScene();
		$cold = $this->reloadProbe($group);
		$shared = $cold->sharedRules();
		// another worker reloads right now
		$shared->lead();
		
		$written = $cold->set('k', 'new', 60, [self::GUARD_TAG]);
		$many = $cold->setMany(['k2' => 'new2'], 60, [self::GUARD_TAG]);
		$read = [
			$other->get('k', queue: false),
			$other->get('k2', queue: false),
		];
		$shared->release();
		$other->clearPhysical();
		
		return $written === false
			&& $many === ['k2' => false]
			&& $read === [null, null];
	}
	
	/**
	 * RULE: while the rules cannot be read, a plain write by a worker with no copy of the rules fetches none,
	 * and deletes the keys it could not write.
	 */
	public function aPlainWriteInAnOutageDeletesTheKey(): bool
	{
		$this->requireSharedRules();
		
		[$group, $other] = $this->plainWriteScene();
		$cold = $this->reloadProbe($group);
		$cold->sharedRules()
			->outageSince(microtime(true) * 1000);
		
		$written = $cold->set('k', 'new', 60, [self::GUARD_TAG]);
		$many = $cold->setMany(['k2' => 'new2'], 60, [self::GUARD_TAG]);
		$read = [
			$other->get('k', queue: false),
			$other->get('k2', queue: false),
		];
		$other->clearPhysical();
		
		return $written === false
			&& $many === ['k2' => false]
			&& $cold->fetchedFrom === []
			&& $read === [null, null];
	}
	
	/**
	 * RULE (probe): a read of the rules that gets an error reply (BUSY, LOADING, MASTERDOWN answer false and
	 * throw nothing) is a failed read, not an empty stream. The copy held stays, the shared copy stays as it
	 * was, the item is served, and the server is in an outage.
	 */
	public function aRulesReadAnsweredByAnErrorReplyKeepsTheHeldSet(): bool
	{
		$this->requireSharedRules();
		
		/** @var RedisVersionedProbe $worker */
		$worker = $this->roundTripStore(RedisVersionedProbe::class, ['rules_cache_ms' => self::RELOAD_CACHE_MS]);
		$worker->setGroup('reload-' . bin2hex(random_bytes(4)));
		$worker->invalidateTags(['unrelated']);
		$worker->set('k', 'value', 60, [self::GUARD_TAG]);
		$before = $worker->get('k', queue: false);
		$held = $worker->rules()
			->last();
		usleep((self::RELOAD_CACHE_MS + 20) * 1000);
		$worker->getConnection()->counter()->erringRanges = 1;
		
		$after = $worker->get('k', queue: false);
		$shared = $worker->sharedRules();
		$sharedLast = $shared->load(2592000000)[0]->last();
		$outage = $shared->lastAttempt()[0];
		$worker->clearPhysical();
		
		return $before === 'value'
			&& $after === 'value'
			&& $sharedLast === $held
			&& $outage
			&& $worker->dropped === 0;
	}
	
	/**
	 * RULE: a manual lockAndQueue() and the set() after it count as the store's own computation of a miss,
	 * like get(). If nothing could check the value, the write leaves the item as it is. That holds once only:
	 * the caller's next set() after its own peek() is the caller's write again, and deletes the key. So does
	 * a set() after the lock was released instead.
	 */
	public function aManualLockThenSetLeavesAnItemItCannotJudge(): bool
	{
		$other = $this->guardStore();
		$locked = $this->guardKey('manual-lock');
		$released = $this->guardKey('manual-release');
		$other->invalidateTags(['unrelated']);
		$other->set($locked, 'valid', 60, [self::GUARD_TAG]);
		$other->set($released, 'valid', 60, [self::GUARD_TAG]);
		$failing = true;
		$probe = $this->rulesFailingProbe($failing);
		
		$value = $probe->lockAndQueue($locked, fn(): string => 'computed');
		$written = $probe->set($locked, $value, 60, [self::GUARD_TAG]);
		$kept = $this->guardStore()->get($locked, queue: false);
		// a read of the caller's, then its set()
		$probe->peek($locked);
		$again = $probe->set($locked, 'new', 60, [self::GUARD_TAG]);
		$probe->lockAndQueue($released, fn(): string => 'computed');
		$probe->releaseActiveLock($released);
		$replaced = $probe->set($released, 'new', 60, [self::GUARD_TAG]);
		$reader = $this->guardStore();
		$read = [
			$reader->get($locked, queue: false),
			$reader->get($released, queue: false),
		];
		$other->delete($locked);
		$other->delete($released);
		
		return $value === 'computed'
			&& $written === false
			&& $kept === 'valid'
			&& $again === false
			&& $replaced === false
			&& $read === [null, null];
	}
	
	/**
	 * RULE (probe): a batch whose replies leave nothing to check reads no rules. A worker with no copy does
	 * not load one (or wait for one) that it would not use.
	 */
	public function aBatchWithNothingToJudgeReadsNoRules(): bool
	{
		/** @var RedisVersionedProbe $probe */
		$probe = $this->roundTripStore(RedisVersionedProbe::class, ['rules_shared_cache' => false]);
		$keys = [
			$this->guardKey('nothing-a'),
			$this->guardKey('nothing-b'),
		];
		$probe->getConnection()->counter()->erringReads = 2;
		
		$values = $probe->getMany($keys);
		
		return $values === [null, null]
			&& $probe->fetchedFrom === [];
	}
	
	/**
	 * RULE: a copy fetched less than rules_cache_ms ago checks a read without a fetch. That holds for a copy
	 * taken from another worker too, and also while a reload runs. With exact reads (rules_cache_ms 0) no
	 * copy counts as fresh, and the read reloads when the reload flag is free.
	 */
	public function aSetYoungerThanTheWindowJudgesTheReadWithoutAFetch(): bool
	{
		$this->requireSharedRules();
		
		[$first, $group, $last] = $this->reloadSetUp(-self::RELOAD_CACHE_MS / 2);
		$worker = $this->reloadProbe($group);
		$read = $worker->get('item1', queue: false);
		$first->sharedRules()
			->release();
		$exact = $this->probe(['rules_cache_ms' => 0]);
		$exact->setGroup($group);
		$exactRead = $exact->get('item1', queue: false);
		
		try
		{
			return $read === 'test'
				&& $worker->fetchedFrom === []
				&& $exactRead === null
				&& $exact->fetchedFrom === [$last];
		}
		finally
		{
			$this->reloadTearDown($first);
		}
	}
	
	/**
	 * RULE: while the rules cannot be read, the copy held checks a read whatever its age, also while another
	 * worker retries, and nothing is fetched.
	 */
	public function inAnOutageTheSetJudgesWhateverItsAge(): bool
	{
		$this->requireSharedRules();
		
		[$first, $group] = $this->copyAged(10000);
		$shared = $first->sharedRules();
		$shared->outageSince(microtime(true) * 1000);
		$worker = $this->reloadProbe($group);
		$read = $worker->get('item1', queue: false);
		// a worker retrying holds the flag: the outage still decides
		$shared->lead();
		$during = $this->reloadProbe($group);
		$duringRead = $during->get('item1', queue: false);
		
		try
		{
			return $read === 'test'
				&& $worker->fetchedFrom === []
				&& $duringRead === 'test'
				&& $during->fetchedFrom === [];
		}
		finally
		{
			$this->reloadTearDown($first);
		}
	}
	
	/**
	 * RULE: while the rules cannot be read, a worker with no copy reads every item as a miss. The value is
	 * computed for the caller without the stampede lock: nothing is stored, so a lock would only make the
	 * others queue. The item stays as it was.
	 */
	public function inAnOutageWithNoSetAMissIsComputedWithoutTheLock(): bool
	{
		$this->requireSharedRules();
		
		[$first, $group] = $this->copyAged(0);
		$shared = $first->sharedRules();
		// a server whose APCu holds no set
		$shared->forget();
		$shared->outageSince(microtime(true) * 1000);
		$client = $first->getClient();
		$id = $first->itemId('item1');
		$mark = $client->hGet($id, Store::KEY_MARK);
		$cold = $this->reloadProbe($group);
		$locked = null;
		
		$value = $cold->get('item1', function() use ($cold, &$locked): string
		{
			$locked = $this->lockHeld($cold, 'item1');
			
			return 'computed';
		}, 60, ['tag1'], queue: true);
		
		try
		{
			return $value === 'computed'
				&& $locked === false
				&& $cold->lookedWithLock === 0
				&& $cold->fetchedFrom === []
				&& $client->hGet($id, Store::KEY_MARK) === $mark;
		}
		finally
		{
			$this->reloadTearDown($first);
		}
	}
	
	/**
	 * RULE: while the rules cannot be read, one worker tries the stream again once RETRY_MS has passed since
	 * the last attempt ended (not since it began). Meanwhile the others use their copy, without a fetch.
	 */
	public function anOutageIsRetriedASecondAfterTheLastAttemptEnded(): bool
	{
		$this->requireSharedRules();
		
		[$first, $group] = $this->copyAged(10000);
		$attempts = [];
		$reads = [];
		// the first attempt takes 300 ms, and fails
		$failing = $this->reloadProbe($group);
		$failing->beforeRuleFetch = static function() use (&$attempts): void
		{
			$attempts[] = 'first';
			usleep(300000);
			
			throw new RedisException('the rules node does not answer');
		};
		$reads[] = $failing->get('item1', queue: false);
		$endedAt = microtime(true);
		
		// 1.1 s after that attempt began, 0.8 s after it ended
		usleep((int)max(0, ($endedAt + 0.8 - microtime(true)) * 1000000));
		$early = $this->reloadProbe($group);
		$early->beforeRuleFetch = $this->recordAttempt($attempts, 'early');
		$reads[] = $early->get('item1', queue: false);
		
		usleep((int)max(0, ($endedAt + (Store::RETRY_MS + 50) / 1000 - microtime(true)) * 1000000));
		$retrier = $this->reloadProbe($group);
		$retrier->beforeRuleFetch = $this->recordAttempt($attempts, 'retrier');
		$reads[] = $retrier->get('item1', queue: false);
		$next = $this->reloadProbe($group);
		$next->beforeRuleFetch = $this->recordAttempt($attempts, 'next');
		$reads[] = $next->get('item1', queue: false);
		
		try
		{
			return $attempts === ['first', 'retrier']
				&& $reads === ['test', 'test', 'test', 'test']
				&& $first->sharedRules()->lastAttempt()[0];
		}
		finally
		{
			$this->reloadTearDown($first);
		}
	}
	
	/**
	 * RULE: a retry that reads the stream ends the outage for the whole server. The retrying worker uses what
	 * it read, and another worker takes that copy and fetches nothing.
	 */
	public function aSuccessfulRetryEndsTheOutageForTheServer(): bool
	{
		$this->requireSharedRules();
		
		[$first, $group, $last] = $this->copyAged(10000);
		$shared = $first->sharedRules();
		$shared->outageSince(microtime(true) * 1000 - Store::RETRY_MS - 100);
		$retrier = $this->reloadProbe($group);
		$read = $retrier->get('item1', queue: false);
		$other = $this->reloadProbe($group);
		$otherRead = $other->get('item1', queue: false);
		
		try
		{
			return $read === null
				&& $retrier->fetchedFrom === [$last]
				&& $shared->lastAttempt()[0] === false
				&& $otherRead === null
				&& $other->fetchedFrom === [];
		}
		finally
		{
			$this->reloadTearDown($first);
		}
	}
	
	/**
	 * RULE: while the rules cannot be read, the copy held checks every read, whatever its age and while a
	 * retry runs: exact reads (rules_cache_ms 0) too, and also when the copy lacks an invalidation made on
	 * this server. Nothing is fetched until the stream answers again.
	 */
	public function inAnOutageExactReadsAndSetsBehindALocalInvalidationAreJudgedByTheSet(): bool
	{
		$this->requireSharedRules();
		
		// a set two seconds old, and a retry in flight
		[$first, $group] = $this->reloadSetUp(2000);
		$first->sharedRules()
			->outageSince(microtime(true) * 1000);
		$exact = $this->probe(['rules_cache_ms' => 0]);
		$exact->setGroup($group);
		$exactRead = $exact->get('item1', queue: false);
		// another worker of this server invalidates; holding no set, it shares
		// none
		$this->reloadProbe($group)
			->invalidateTags(['tag3']);
		$behind = $this->reloadProbe($group);
		$behindRead = $behind->get('item1', queue: false);
		
		try
		{
			return $exactRead === 'test'
				&& $exact->fetchedFrom === []
				&& $behindRead === 'test'
				&& $behind->fetchedFrom === [];
		}
		finally
		{
			$this->reloadTearDown($first);
		}
	}
	
	/**
	 * RULE: without a shared copy, an outage belongs to the instance alone. After one failed attempt its copy
	 * checks its reads until RETRY_MS has passed. Another instance tries for itself.
	 */
	public function withoutASharedSetTheOutageIsTheInstances(): bool
	{
		$options = [
			'rules_shared_cache' => false,
			'rules_cache_ms' => 50,
		];
		$group = 'reload-' . bin2hex(random_bytes(4));
		$writer = $this->probe($options);
		$writer->setGroup($group);
		$writer->invalidateTags(['unrelated']);
		$writer->set('item1', 'test', 60, ['tag1']);
		$reader = $this->probe($options);
		$reader->setGroup($group);
		$first = $reader->get('item1', queue: false);
		usleep(60000);
		$attempts = [];
		$reader->beforeRuleFetch = $this->recordAttempt($attempts, 'reader');
		$reads = [];
		for($i = 0; $i < 5; $i++)
		{
			$reads[] = $reader->get('item1', queue: false);
		}
		$other = $this->probe($options);
		$other->setGroup($group);
		$other->beforeRuleFetch = $this->recordAttempt($attempts, 'other');
		$otherRead = $other->get('item1', queue: false);
		$writer->clearPhysical();
		
		return $first === 'test'
			&& $reads === ['test', 'test', 'test', 'test', 'test']
			&& $attempts === ['reader', 'other']
			&& $otherRead === null;
	}
	
	/**
	 * RULE: a reload another worker began more than SLOW_RELOAD_MS ago counts as an outage for the other
	 * workers of the server. A copy of any age checks the read at once, and nothing is fetched besides that
	 * reload.
	 */
	public function aReloadInFlightForMoreThanASecondIsAnOutageForTheOthers(): bool
	{
		$this->requireSharedRules();
		
		[$first, $group] = $this->copyAged(3000);
		$first->sharedRules()
			->leaseSince(microtime(true) * 1000 - Store::SLOW_RELOAD_MS - 200);
		$worker = $this->reloadProbe($group);
		$read = $worker->get('item1', queue: false);
		
		try
		{
			return $read === 'test'
				&& $worker->fetchedFrom === [];
		}
		finally
		{
			$first->sharedRules()
				->forget();
			$this->reloadTearDown($first);
		}
	}
	
	/**
	 * RULE (processes): a reload that hangs past SLOW_RELOAD_MS slows down only its own request. Workers
	 * reading 1.2 s into it use a copy three seconds old, at once, and fetch nothing. The reloaded copy is
	 * stored once Redis answers.
	 */
	public function aHangingReloadStallsOnlyItsOwnRequest(): bool
	{
		$this->requireSharedRules();
		
		$directory = $this->rulesDirectory();
		$startAt = microtime(true) + self::PROCESS_WARM_UP_S;
		[$group, $last, $first] = $this->processScene($directory, 3000, $startAt);
		
		try
		{
			$reports = $this->readInProcesses($group, $directory, $startAt, [
				'reloader' => [0, 2500, false],
				'a' => [1200, 0, false],
				'b' => [1200, 0, false],
				'c' => [1250, 0, false],
				'd' => [1300, 0, false],
			]);
			$sharedLast = $first->sharedRules()->load(2592000000)[0]->last();
			
			$others = true;
			foreach(['a', 'b', 'c', 'd'] as $name)
			{
				$others = $others
					&& ($reports[$name]['value'] ?? null) === 'test'
					&& ($reports[$name]['attempts'] ?? null) === 0
					&& ($reports[$name]['ms'] ?? 1000) < 200;
			}
			
			return ($reports['reloader']['attempts'] ?? null) === 1
				&& ($reports['reloader']['ms'] ?? 0) > 2400
				&& $others
				&& $sharedLast !== $last;
		}
		finally
		{
			$first->clearPhysical();
			$this->removeDirectory($directory);
		}
	}
	
	/**
	 * RULE (processes): with the reload flag free, of six workers reading at once, one reloads for the server
	 * and shares what it read. Nobody else fetches or waits for it.
	 */
	public function withTheFlagFreeOneOfManyWorkersReloadsAndNoneWaits(): bool
	{
		$this->requireSharedRules();
		
		$directory = $this->rulesDirectory();
		$startAt = microtime(true) + self::PROCESS_WARM_UP_S;
		[$group, $last, $first] = $this->processScene($directory, 300, $startAt);
		
		try
		{
			$reports = $this->readInProcesses($group, $directory, $startAt, array_fill(0, 6, [0, 500, false]));
			$head = array_key_first((array)$first->getClient()->xRevRange($first->getRulesKey(), '+', '-', 1));
			$sharedLast = $first->sharedRules()->load(2592000000)[0]->last();
			
			$attempts = 0;
			$waited = false;
			foreach($reports as $report)
			{
				$attempts+= $report['attempts'] ?? 0;
				if(($report['attempts'] ?? 0) === 1)
				{
					$waited = $waited || $report['value'] !== null || $report['ms'] < 450;
					
					continue;
				}
				
				$waited = $waited || ($report['ms'] ?? 1000) >= 250;
			}
			
			return in_array(null, $reports, true) === false
				&& $attempts === 1
				&& $waited === false
				&& $sharedLast === $head
				&& $head !== $last;
		}
		finally
		{
			$first->clearPhysical();
			$this->removeDirectory($directory);
		}
	}
	
	/**
	 * RULE (processes): while the rules cannot be read, six workers read at once, a second after the last
	 * attempt ended. One of them tries the stream again. Every one uses the copy it holds, and those that did
	 * not try did not wait.
	 */
	public function inAnOutageOneOfManyWorkersRetries(): bool
	{
		$this->requireSharedRules();
		
		$directory = $this->rulesDirectory();
		$startAt = microtime(true) + self::PROCESS_WARM_UP_S;
		[$group, , $first] = $this->processScene($directory, 3000, $startAt);
		$first->sharedRules()
			->outageSince($startAt * 1000 - Store::RETRY_MS - 100);
		
		try
		{
			$reports = $this->readInProcesses($group, $directory, $startAt, array_fill(0, 6, [0, 400, true]));
			
			$attempts = 0;
			$values = [];
			$waited = false;
			foreach($reports as $report)
			{
				$attempts+= $report['attempts'] ?? 0;
				$values[] = $report['value'] ?? null;
				$waited = $waited
					|| (($report['attempts'] ?? 0) === 0 && ($report['ms'] ?? 1000) >= 250);
			}
			
			return $attempts === 1
				&& $values === ['test', 'test', 'test', 'test', 'test', 'test']
				&& $waited === false;
		}
		finally
		{
			$first->clearPhysical();
			$this->removeDirectory($directory);
		}
	}
	
	/**
	 * RULE (processes): a reloader that dies of a fatal error while it holds the reload flag lets go of it as
	 * its process shuts down. Otherwise the lease would keep the flag taken for LEASE_MS.
	 */
	public function aDyingReloaderLetsGoOfTheFlag(): bool
	{
		$this->requireSharedRules();
		
		$directory = $this->rulesDirectory();
		$startAt = microtime(true) + self::PROCESS_WARM_UP_S;
		[$group, , $first] = $this->processScene($directory, 300, $startAt);
		
		try
		{
			$reports = $this->readInProcesses($group, $directory, $startAt, [
				'dying' => [0, 0, false, 'die'],
			]);
			
			// it reached its fetch, holding the flag, and did not come back
			return is_file($directory . DIRECTORY_SEPARATOR . 'fetching-dying')
				&& $reports['dying'] === null
				&& $first->sharedRules()->reloadStartedAt() === null;
		}
		finally
		{
			$first->clearPhysical();
			$this->removeDirectory($directory);
		}
	}
	
	/**
	 * RULE: with the reload flag free, a worker reloads for the server: one fetch from the copy's newest id,
	 * and the flag is let go after. It shares what it read, and the next worker takes that copy and fetches
	 * nothing.
	 */
	public function withTheFlagFreeAWorkerReloadsAndSharesTheSet(): bool
	{
		$this->requireSharedRules();
		
		[$first, $group, $last] = $this->copyAged(300);
		$shared = $first->sharedRules();
		$reloader = $this->reloadProbe($group);
		$read = $reloader->get('item1', queue: false);
		$head = array_key_first((array)$first->getClient()->xRevRange($first->getRulesKey(), '+', '-', 1));
		$sharedLast = $shared->load(2592000000)[0]->last();
		$next = $this->reloadProbe($group);
		$nextRead = $next->get('item1', queue: false);
		
		try
		{
			return $read === null
				&& $reloader->fetchedFrom === [$last]
				&& $sharedLast === $head
				&& $shared->reloadStartedAt() === null
				&& $nextRead === null
				&& $next->fetchedFrom === [];
		}
		finally
		{
			$this->reloadTearDown($first);
		}
	}
	
	/**
	 * RULE: a reload that finds the stream started over (lost, its ids gone) replaces the shared copy, even
	 * though the shared copy has newer ids. A worker holding the copy of the lost stream takes the one read
	 * later instead of reloading.
	 */
	public function aReloadThatStartsOverReplacesTheSharedSet(): bool
	{
		$this->requireSharedRules();
		
		[$first, $group] = $this->copyAged(-self::RELOAD_CACHE_MS / 2);
		$holder = $this->reloadProbe($group);
		$holder->get('item1', queue: false);
		$first->getClient()
			->del($first->getRulesKey());
		usleep((self::RELOAD_CACHE_MS + 20) * 1000);
		$this->reloadProbe($group)
			->get('item1', queue: false);
		$sharedLast = $first->sharedRules()->load(2592000000)[0]->last();
		$holder->get('item1', queue: false);
		
		try
		{
			return $sharedLast === Rules::NONE
				&& $holder->fetchedFrom === [];
		}
		finally
		{
			$this->reloadTearDown($first);
		}
	}
	
	/**
	 * RULE: a reload whose fetch fails keeps the copy held, which checks the read, and logs the failure. It
	 * starts an outage: another worker of the server uses its own copy and leaves the retry for later.
	 */
	public function aReloadThatFailsKeepsTheSetAndStartsAnOutage(): bool
	{
		$this->requireSharedRules();
		
		[$first, $group, $last] = $this->copyAged(5000);
		$second = $this->reloadProbe($group);
		$failure = new RedisException('the rules stream cannot be read');
		$second->beforeRuleFetch = static function() use ($failure): void
		{
			throw $failure;
		};
		$read = $second->get('item1', queue: false);
		$third = $this->reloadProbe($group);
		$thirdRead = $third->get('item1', queue: false);
		
		try
		{
			return $read === 'test'
				&& $second->fetchedFrom === [$last]
				&& in_array($failure, array_column($second->logged, 0), true)
				&& $first->sharedRules()->lastAttempt()[0]
				&& $thirdRead === 'test'
				&& $third->fetchedFrom === [];
		}
		finally
		{
			$this->reloadTearDown($first);
		}
	}
	
	/**
	 * RULE: a worker that takes the reload flag looks once more before it fetches. A copy another worker
	 * stored between its first look and taking the flag is used. So is the record of an attempt that failed
	 * meanwhile: then the copy held checks the read. In neither case is anything fetched.
	 */
	public function aWorkerThatTakesTheFlagAfterAnotherLandedOrTriedFetchesNothing(): bool
	{
		$this->requireSharedRules();
		
		$reads = [];
		$fetched = [];
		foreach([true, false] as $landed)
		{
			[$first, $group] = $this->copyAged(5000);
			$shared = $first->sharedRules();
			$loader = $this->probe(['rules_shared_cache' => false]);
			$loader->setGroup($group);
			$rules = $loader->rules();
			$worker = $this->reloadProbe($group);
			$looks = 0;
			// the second look is the one made with the flag
			$worker->beforeAdopt = static function() use (&$looks, $landed, $shared, $rules): void
			{
				if($looks++ !== 1)
				{
					return;
				}
				
				$nowMs = microtime(true) * 1000;
				$shared->attemptEnded($nowMs, $landed, $nowMs);
				if($landed)
				{
					$shared->store($rules, $nowMs - 10);
				}
			};
			$reads[] = $worker->get('item1', queue: false);
			$fetched[] = $worker->fetchedFrom;
			$this->reloadTearDown($first);
		}
		
		return $reads === [null, 'test']
			&& $fetched === [[], []];
	}
	
	/**
	 * RULE: where APCu refuses to record the reload flag, the worker works alone: it loads the rules itself
	 * from its own copy's newest id, shares nothing, and warns once.
	 */
	public function aFlagApcuRefusesMakesTheWorkerAServerOfOne(): bool
	{
		$this->requireSharedRules();
		
		[$first, $group] = $this->copyAged(0);
		$first->set('item2', 'fresh', 60, ['tag9']);
		$directory = $this->rulesDirectory();
		$worker = $this->reloadProbe($group);
		$worker->sharedRulesFactory = static function(string $rulesKey, string $identity) use ($directory): SharedRulesProbe
		{
			$shared = new SharedRulesInFiles($rulesKey, $identity, $directory);
			$shared->refuses = static fn(string $key): bool => true;
			
			return $shared;
		};
		
		try
		{
			$reads = [$worker->get('item2', queue: false)];
			$held = $worker->readVerdict()[0]?->last();
			usleep((self::RELOAD_CACHE_MS + 20) * 1000);
			$reads[] = $worker->get('item2', queue: false);
			
			return $reads === ['fresh', 'fresh']
				&& $worker->fetchedFrom === [Rules::NONE, $held]
				&& $this->countLogged($worker, 'refused the rule set\'s reload flag') === 1
				&& $worker->sharedRules()->load(2592000000) === null;
		}
		finally
		{
			$this->reloadTearDown($first);
			$this->removeDirectory($directory);
		}
	}
	
	/**
	 * RULE: where APCu takes the reload flag but refuses to store the copy, the worker warns once. The other
	 * workers keep using the older copy there, and nothing else would show it.
	 */
	public function aRefusedRuleSetIsWarnedOfOnce(): bool
	{
		$this->requireSharedRules();
		
		[$first, $group] = $this->copyAged(0);
		$directory = $this->rulesDirectory();
		$worker = $this->reloadProbe($group);
		$worker->sharedRulesFactory = static function(string $rulesKey, string $identity) use ($directory): SharedRulesProbe
		{
			$shared = new SharedRulesInFiles($rulesKey, $identity, $directory);
			// the set, not the flag, the mark or the attempt
			$shared->refuses = static fn(string $key): bool => $key === $shared->getKey();
			
			return $shared;
		};
		
		try
		{
			$worker->get('item1', queue: false);
			usleep((self::RELOAD_CACHE_MS + 20) * 1000);
			$worker->get('item1', queue: false);
			
			return count($worker->fetchedFrom) === 2
				&& $this->countLogged($worker, 'refused the rule set:') === 1
				&& $worker->sharedRules()->load(2592000000) === null;
		}
		finally
		{
			$this->reloadTearDown($first);
			$this->removeDirectory($directory);
		}
	}
	
	/**
	 * RULE: a newer copy already shared does not count as a refusal: the reload leaves it there and warns of
	 * nothing.
	 */
	public function aNewerSetAlreadySharedIsNoRefusal(): bool
	{
		$this->requireSharedRules();
		
		[$first, $group] = $this->copyAged(5000);
		$shared = $first->sharedRules();
		$newer = Rules::fromArray([
			'tags' => [],
			'all' => [],
			'last' => '99999999999999-0',
			'first' => '99999999999999-0',
			'first_opened' => false,
		], 2592000000);
		$worker = $this->reloadProbe($group);
		// another worker shares a newer set while this one fetches
		$worker->beforeRuleFetch = static function() use ($shared, $newer): void
		{
			$shared->store($newer, microtime(true) * 1000);
		};
		$worker->get('item1', queue: false);
		
		try
		{
			return count($worker->fetchedFrom) === 1
				&& $this->countLogged($worker, 'refused the rule set') === 0
				&& $shared->load(2592000000)[0]->last() === '99999999999999-0';
		}
		finally
		{
			$this->reloadTearDown($first);
		}
	}
	
	/**
	 * RULE: while another worker's reload has run for at most SLOW_RELOAD_MS, a copy fetched up to
	 * rules_cache_ms + SLOW_RELOAD_MS ago checks the read at once, and nothing is fetched besides the reload.
	 * So an invalidation made on another server is seen within rules_cache_ms + 1 s.
	 */
	public function whileAnotherWorkerReloadsASetUpToASecondPastTheWindowJudgesTheRead(): bool
	{
		$this->requireSharedRules();
		
		[$first, $group] = $this->reloadSetUp(Store::SLOW_RELOAD_MS - 300);
		$worker = $this->reloadProbe($group);
		$read = $worker->get('item1', queue: false);
		
		try
		{
			return $read === 'test'
				&& $worker->fetchedFrom === [];
		}
		finally
		{
			$this->reloadTearDown($first);
		}
	}
	
	/**
	 * RULE: while another worker reloads, exact reads (rules_cache_ms 0) are checked too, by a copy fetched
	 * up to SLOW_RELOAD_MS ago. An older copy does not check them, so every item is a miss. Neither case
	 * fetches.
	 */
	public function whileAnotherWorkerReloadsExactReadsAreJudgedByASetUpToASecondOld(): bool
	{
		$this->requireSharedRules();
		
		[$first, $group] = $this->reloadSetUp(400 - self::RELOAD_CACHE_MS);
		$shared = $first->sharedRules();
		$young = $this->probe(['rules_cache_ms' => 0]);
		$young->setGroup($group);
		$youngRead = $young->get('item1', queue: false);
		$shared->store($first->rules(), microtime(true) * 1000 - Store::SLOW_RELOAD_MS - 200);
		$old = $this->probe(['rules_cache_ms' => 0]);
		$old->setGroup($group);
		$oldRead = $old->get('item1', queue: false);
		
		try
		{
			return $youngRead === 'test'
				&& $young->fetchedFrom === []
				&& $oldRead === null
				&& $old->fetchedFrom === [];
		}
		finally
		{
			$this->reloadTearDown($first);
		}
	}
	
	/**
	 * RULE: while another worker reloads, a copy too old to use makes every item a miss, computed without the
	 * stampede lock, because a worker waiting for the lock would read a miss as well. The value is stored
	 * with that copy's newest rule id, which only makes the item outdated sooner. Nothing is fetched besides
	 * the reload.
	 */
	public function whileAnotherWorkerReloadsAnOlderSetMissesWithoutTheLockAndStoresStampedFromIt(): bool
	{
		$this->requireSharedRules();
		
		[$first, $group, $last] = $this->reloadSetUp(Store::SLOW_RELOAD_MS + 500);
		$worker = $this->reloadProbe($group);
		$locked = null;
		
		$value = $worker->get('item1', function() use ($worker, &$locked): string
		{
			$locked = $this->lockHeld($worker, 'item1');
			
			return 'recomputed';
		}, 60, ['tag1'], queue: true);
		$mark = $first->getClient()
			->hGet($first->itemId('item1'), Store::KEY_MARK);
		
		try
		{
			return $value === 'recomputed'
				&& $locked === false
				&& $worker->lookedWithLock === 0
				&& $worker->fetchedFrom === []
				&& $mark === $last;
		}
		finally
		{
			$this->reloadTearDown($first);
		}
	}
	
	/**
	 * RULE: while another worker reloads, a worker with no copy reads every item as a miss, computed without
	 * the stampede lock, and stores nothing. The item stays as it was.
	 */
	public function whileAnotherWorkerReloadsAWorkerHoldingNoSetStoresNothing(): bool
	{
		$this->requireSharedRules();
		
		[$first, $group] = $this->reloadSetUp(0);
		$shared = $first->sharedRules();
		// a server whose APCu holds no set, its reload in flight
		$shared->release();
		$shared->forget();
		$shared->lead();
		$client = $first->getClient();
		$id = $first->itemId('item1');
		$mark = $client->hGet($id, Store::KEY_MARK);
		$cold = $this->reloadProbe($group);
		$locked = null;
		
		$value = $cold->get('item1', function() use ($cold, &$locked): string
		{
			$locked = $this->lockHeld($cold, 'item1');
			
			return 'computed';
		}, 60, ['tag1'], queue: true);
		
		try
		{
			return $value === 'computed'
				&& $locked === false
				&& $cold->fetchedFrom === []
				&& $client->hGet($id, Store::KEY_MARK) === $mark;
		}
		finally
		{
			$this->reloadTearDown($first);
		}
	}
	
	/**
	 * RULE: while another worker reloads, a young copy that may lack an invalidation made on this server
	 * checks nothing. Every item is a miss, computed without the lock, and the value the rule reached is
	 * never served. Nothing is fetched besides the reload.
	 */
	public function whileAnotherWorkerReloadsAYoungSetBehindALocalInvalidationMisses(): bool
	{
		$this->requireSharedRules();
		
		[$first, $group] = $this->reloadSetUp(-self::RELOAD_CACHE_MS / 2);
		$worker = $this->reloadProbe($group);
		$before = $worker->get('item1', queue: false);
		// another worker of this server, holding no set
		$this->reloadProbe($group)
			->invalidateTags(['tag1']);
		$locked = null;
		
		$after = $worker->get('item1', function() use ($worker, &$locked): string
		{
			$locked = $this->lockHeld($worker, 'item1');
			
			return 'computed';
		}, 60, ['tag1'], queue: true);
		
		try
		{
			return $before === 'test'
				&& $after === 'computed'
				&& $locked === false
				&& $worker->fetchedFrom === [];
		}
		finally
		{
			$this->reloadTearDown($first);
		}
	}
	
	/**
	 * RULE: a batch whose reads the store could not check computes its misses in one resolver call, without
	 * their stampede locks.
	 */
	public function aBatchTheStoreCouldNotJudgeTakesNoLocks(): bool
	{
		$this->requireSharedRules();
		
		[$first, $group] = $this->reloadSetUp(Store::SLOW_RELOAD_MS + 500);
		$worker = $this->reloadProbe($group);
		$calls = 0;
		$locked = [];
		
		$values = $worker->getMany(['item1', 'item9'], function(KeyValue $store, array $keys) use ($worker, &$calls, &$locked): array
		{
			$calls++;
			foreach($keys as $key)
			{
				$locked[] = $this->lockHeld($worker, $key);
			}
			
			return array_fill_keys($keys, 'computed');
		}, 60, ['tag1'], queue: true);
		
		try
		{
			return $values === ['computed', 'computed']
				&& $calls === 1
				&& $locked === [false, false]
				&& $worker->fetchedFrom === [];
		}
		finally
		{
			$this->reloadTearDown($first);
		}
	}
	
	/**
	 * RULE (probe): a plain write gets its copy the way a read does (see RedisVersioned::readRules()) and is
	 * stored with that copy's newest rule id, never the stream's newest id. While another worker reloads, and
	 * while the rules cannot be read, it uses the copy held as it is. With the reload flag free it reloads
	 * for the server first. No XREVRANGE, one XRANGE.
	 */
	public function aPlainWriteTakesItsSetThroughTheReadTable(): bool
	{
		$this->requireSharedRules();
		
		[$first, $group, $last] = $this->reloadSetUp(60000);
		$shared = $first->sharedRules();
		/** @var RedisVersionedProbe $worker */
		$worker = $this->roundTripStore(RedisVersionedProbe::class, ['rules_cache_ms' => self::RELOAD_CACHE_MS]);
		$worker->setGroup($group);
		$counter = $worker->getConnection()->counter();
		$worker->getClient();
		$counter->resetCount();
		
		$written = [$worker->set('w1', 'v1', 60, ['tag3'])];
		$shared->release();
		$shared->outageSince(microtime(true) * 1000);
		$written[] = $worker->set('w2', 'v2', 60, ['tag3']);
		$shared->attemptEnded(microtime(true) * 1000, true, microtime(true) * 1000);
		$written[] = $worker->set('w3', 'v3', 60, ['tag3']);
		$sent = $counter->sent;
		$client = $worker->getClient();
		$head = array_key_first((array)$client->xRevRange($worker->getRulesKey(), '+', '-', 1));
		$marks = [
			$client->hGet($worker->itemId('w1'), Store::KEY_MARK),
			$client->hGet($worker->itemId('w2'), Store::KEY_MARK),
			$client->hGet($worker->itemId('w3'), Store::KEY_MARK),
		];
		
		$ranges = 0;
		$revRanges = 0;
		foreach($sent as $commands)
		{
			$ranges+= count(array_intersect($commands, ['xRange']));
			$revRanges+= count(array_intersect($commands, ['xRevRange']));
		}
		
		try
		{
			return $written === [true, true, true]
				&& $ranges === 1
				&& $revRanges === 0
				&& $marks === [$last, $last, $head];
		}
		finally
		{
			$this->reloadTearDown($first);
		}
	}
	
	/**
	 * RULE (probe): after a failed read, the store's own computation stores nothing and leaves the key, which
	 * may hold a valid item. A write that replaces the value deletes the key instead
	 * (TraitInvalidationGuard).
	 */
	public function anOwnComputationAfterAFailedReadLeavesTheKey(): bool
	{
		$reader = $this->unreadProbe();
		$other = $this->guardStore();
		$key = $this->guardKey('unread-own');
		$other->set($key, 'valid', 60);
		// the read, and the look made with the lock
		$reader->getConnection()->counter()->failingReads = 2;
		
		$value = $reader->get($key, fn(): string => 'computed', 60);
		$read = $this->guardStore()->get($key, queue: false);
		$other->delete($key);
		
		return $value === 'computed'
			&& $read === 'valid';
	}
	
	/**
	 * RULE: a value longer than the Compressor reads back, computed by the store after its miss (get() and
	 * getMany()), is not stored and leaves the key. The outdated item there stays, to be checked again by the
	 * next read. A plain write of such a value deletes the key (anOversizeValueIsNotSentAndLoggedOnce).
	 */
	public function anOwnComputationTooLongForTheCompressorLeavesTheKey(): bool
	{
		$probe = $this->probe([
			'rules_cache_ms' => 0,
			'rules_shared_cache' => false,
		]);
		$this->capCompressor($probe);
		$other = $this->guardStore();
		$single = $this->guardKey('oversize-own');
		$batch = $this->guardKey('oversize-own-batch');
		$other->invalidateTags(['unrelated']);
		$other->set($single, 'old', 60, [self::GUARD_TAG]);
		$other->set($batch, 'old', 60, [self::GUARD_TAG]);
		$other->invalidateTags([self::GUARD_TAG]);
		$big = str_repeat('x', 4096);
		
		$value = $probe->get($single, fn(): string => $big, 60, [self::GUARD_TAG], queue: false);
		$values = $probe->getMany([$batch], fn(KeyValue $store, array $keys): array => [$batch => $big], 60, [self::GUARD_TAG], queue: false);
		$client = $other->getClient();
		$kept = [
			$client->hExists($other->itemId($single), Store::KEY_DATA),
			$client->hExists($other->itemId($batch), Store::KEY_DATA),
		];
		$other->delete($single);
		$other->delete($batch);
		
		return $value === $big
			&& $values === [$big]
			&& $kept === [true, true];
	}
	
	/**
	 * RULE: a read with no copy of the rules at hand refuses its write even past the guard window, once. That
	 * holds also when a copy is at hand by the time of the write. The store's own computation leaves the key,
	 * and the next write is stored.
	 */
	public function aReadNoRuleSetJudgedRefusesItsWritePastTheWindowOnce(): bool
	{
		$other = $this->guardStore();
		$key = $this->guardKey('unjudged-late-once');
		$other->invalidateTags(['unrelated']);
		$other->set($key, 'valid', 60, [self::GUARD_TAG]);
		$failing = true;
		$probe = $this->rulesFailingProbe($failing);
		$probe->setInvalidationWindowMs(50);
		
		$value = $probe->get($key, function() use ($probe, &$failing): string
		{
			// past the window and the retry: the set is held when the value is written
			usleep((Store::RETRY_MS + 50) * 1000);
			$failing = false;
			$probe->rules();
			
			return 'late';
		}, 60, [self::GUARD_TAG], queue: false);
		$kept = $this->guardStore()->get($key, queue: false);
		$written = $probe->set($key, 'next', 60, [self::GUARD_TAG]);
		$next = $this->guardStore()->get($key, queue: false);
		$other->delete($key);
		
		return $value === 'late'
			&& $kept === 'valid'
			&& $written
			&& $next === 'next';
	}
	
	/**
	 * RULE: when the new rule comes right after the copy the invalidating process holds, it goes into that
	 * copy from Redis's reply, and the copy is shared at once. Another worker takes it and fetches nothing:
	 * the item the rule reaches is a miss, an item it does not reach a hit.
	 */
	public function anotherWorkerAdoptsTheSetAnInvalidationHereSharedAtOnce(): bool
	{
		$this->requireSharedRules();
		
		[$invalidator, $group] = $this->keptAndGone();
		$worker = $this->probe(['rules_cache_ms' => 60000]);
		$worker->setGroup($group);
		$before = [
			$worker->get('kept', queue: false),
			$worker->get('gone', queue: false),
		];
		
		$invalidator->invalidateTags(['gone']);
		$after = [
			$worker->get('kept', queue: false),
			$worker->get('gone', queue: false),
		];
		$invalidator->clearPhysical();
		
		return $before === ['kept', 'gone']
			&& $after === ['kept', null]
			&& $worker->fetchedFrom === []
			&& $invalidator->shares === 1;
	}
	
	/**
	 * RULE: the copy an invalidation shared at once reaches the next request on this server, even when the
	 * invalidating request reads nothing more. The next request takes it and fetches nothing.
	 */
	public function aSetSharedAtOnceReachesTheNextRequest(): bool
	{
		$this->requireSharedRules();
		
		[$invalidator, $group] = $this->keptAndGone();
		$invalidator->invalidateTags(['gone']);
		$next = $this->probe(['rules_cache_ms' => 60000]);
		$next->setGroup($group);
		$read = [
			$next->get('kept', queue: false),
			$next->get('gone', queue: false),
		];
		$invalidator->clearPhysical();
		
		return $read === ['kept', null]
			&& $next->fetchedFrom === [];
	}
	
	/**
	 * RULE: the copy is shared before the invalidation is recorded for the server (SharedRules::written()).
	 * So a worker that sees the record finds a shared copy that already holds the rule.
	 */
	public function aWorkerThatSeesTheMarkFindsTheRuleAlreadyShared(): bool
	{
		$this->requireSharedRules();
		
		[$invalidator] = $this->keptAndGone();
		$shared = $invalidator->sharedRules();
		$seen = null;
		// the first write of the mark
		$shared->beforeWrite = static function(string $key) use ($shared, &$seen): void
		{
			if($shared->isMark($key)
				&& $seen === null)
			{
				$seen = $shared->load(2592000000)[0]->last();
			}
		};
		
		$invalidator->invalidateTags(['gone']);
		$rule = $invalidator->rules()
			->last();
		$shared->beforeWrite = null;
		$invalidator->clearPhysical();
		
		return $seen === $rule;
	}
	
	/**
	 * RULE: when another rule was added between the end of a process's copy and its own new rule, nothing
	 * goes into the copy and nothing is shared, because the copy would skip that rule. The process's copy
	 * then lacks its own rule. Its next read reloads (the reload flag is free) and sees both.
	 */
	public function afterARuleFromElsewhereInBetweenNothingIsSharedAndTheProcessReloads(): bool
	{
		$this->requireSharedRules();
		
		[$invalidator, , $last] = $this->keptAndGone();
		$invalidator->getClient()
			->xAdd($invalidator->getRulesKey(), '*', ['mode' => 'any', 'tags' => 'kept', 'first' => '0']);
		$invalidator->invalidateTags(['gone']);
		$sharedLast = $invalidator->sharedRules()->load(2592000000)[0]->last();
		$read = [
			$invalidator->get('kept', queue: false),
			$invalidator->get('gone', queue: false),
		];
		$invalidator->clearPhysical();
		
		return $sharedLast === $last
			&& $invalidator->shares === 0
			&& $read === [null, null]
			&& $invalidator->fetchedFrom === [Rules::NONE, $last];
	}
	
	/**
	 * RULE: a loop of invalidations shares the copy twice: at once at the first, and once more when the
	 * instance goes away, never at a read in between. So a loop does not copy the whole set per call.
	 */
	public function aLoopOfInvalidationsSharesTheSetTwice(): bool
	{
		$this->requireSharedRules();
		
		[$invalidator, $group] = $this->keptAndGone();
		$shares = 0;
		$invalidator->onShare = static function() use (&$shares): void
		{
			$shares++;
		};
		for($i = 0; $i < 50; $i++)
		{
			$invalidator->invalidateTags(['loop' . $i]);
		}
		$inLoop = $shares;
		$invalidator->get('kept', queue: false);
		$rule = $invalidator->rules()
			->last();
		$atRead = $shares;
		$shared = $invalidator->sharedRules();
		
		unset($invalidator);
		gc_collect_cycles();
		$sharedLast = $shared->load(2592000000)[0]->last();
		$this->reloadProbe($group)
			->clearPhysical();
		
		return $inLoop === 1
			&& $atRead === 1
			&& $shares === 2
			&& $sharedLast === $rule;
	}
	
	/**
	 * RULE: between the first invalidation of an instance and its end, the other workers of the server lack
	 * its later rules. One of them takes the reload flag, reloads, and sees them.
	 */
	public function midLoopAnotherWorkerIsBehindAndReloadsThroughTheFlag(): bool
	{
		$this->requireSharedRules();
		
		[$invalidator, $group] = $this->keptAndGone();
		$invalidator->invalidateTags(['unrelated2']);
		$shared = $invalidator->rules()
			->last();
		$invalidator->invalidateTags(['gone']);
		$worker = $this->probe(['rules_cache_ms' => 60000]);
		$worker->setGroup($group);
		$read = $worker->get('gone', queue: false);
		$invalidator->clearPhysical();
		
		return $read === null
			&& $worker->fetchedFrom === [$shared];
	}
	
	/**
	 * RULE: an instance that switches to another group first shares the old group's copy, if that copy holds
	 * invalidations of its own that are not shared yet.
	 */
	public function aGroupChangeSharesTheOldGroupsSetFirst(): bool
	{
		$this->requireSharedRules();
		
		[$invalidator, $group] = $this->keptAndGone();
		$invalidator->invalidateTags(['unrelated2']);
		$invalidator->invalidateTags(['gone']);
		$rule = $invalidator->rules()
			->last();
		$shared = $invalidator->sharedRules();
		$invalidator->setGroup('reload-' . bin2hex(random_bytes(4)));
		$sharedLast = $shared->load(2592000000)[0]->last();
		$this->reloadProbe($group)
			->clearPhysical();
		
		return $sharedLast === $rule;
	}
	
	/**
	 * RULE: the reload flag is a lease. One worker holds it. A release by any other worker leaves it, and its
	 * owner's release frees it. A lease older than LEASE_MS is free again, and a release by its old owner
	 * then leaves the worker that took it over.
	 */
	public function theFlagIsALeaseOnlyItsOwnerReleases(): bool
	{
		$this->requireSharedRules();
		
		$key = 'tests:lease-' . bin2hex(random_bytes(4));
		$a = new SharedRulesProbe($key);
		$b = new SharedRulesProbe($key);
		
		$taken = [$a->lead(), $b->lead()];
		$b->release();
		$heldAfterAnother = $a->reloadStartedAt() !== null;
		$a->release();
		$freed = $a->reloadStartedAt() === null;
		
		$a->lead();
		// a's lease ran out: what the flag holds began LEASE_MS ago
		$a->leaseSince(microtime(true) * 1000 - SharedRules::LEASE_MS - 10);
		$lapsedTaken = $b->lead();
		$a->release();
		$stillB = $b->reloadStartedAt() !== null;
		$b->release();
		$a->forget();
		
		return $taken === [true, false]
			&& $heldAfterAnother
			&& $freed
			&& $lapsedTaken === true
			&& $stillB;
	}
	
	/**
	 * RULE: a failure reported by an attempt that began before the last success is ignored, because that
	 * success read the stream later. A failure of an attempt that began after it starts an outage.
	 */
	public function aFailureOfAnAttemptOlderThanTheLastSuccessIsIgnored(): bool
	{
		$this->requireSharedRules();
		
		$shared = new SharedRulesProbe('tests:attempt-' . bin2hex(random_bytes(4)));
		$nowMs = microtime(true) * 1000;
		$shared->attemptEnded($nowMs - 100, true, $nowMs - 50);
		$shared->attemptEnded($nowMs - 200, false, $nowMs);
		$ignored = $shared->lastAttempt()[0] === false;
		$shared->attemptEnded($nowMs - 40, false, $nowMs);
		$counted = $shared->lastAttempt()[0];
		$shared->forget();
		
		return $ignored
			&& $counted;
	}
	
	/**
	 * RULE: the second read made with the lock is a miss when the copy may no longer check it: another worker
	 * started reloading, and an invalidation made on this server meanwhile means the copy may lack its rule.
	 * The lock is kept for the computation and released with its write.
	 */
	public function aLookTheSetMayNotJudgeKeepsTheLock(): bool
	{
		$this->requireSharedRules();
		
		[$first, $group] = $this->copyAged(-self::RELOAD_CACHE_MS / 2);
		$probe = $this->reloadProbe($group);
		$lockKey = $probe->getMemoLock()
			->getPrefixer()
			->prefix(MemoLock::TYPE_LOCK, $probe->itemId('fresh'));
		// a value lands, then a reload starts and the value's tag is invalidated
		$probe->beforeLock = function() use ($first, $group): void
		{
			$first->set('fresh', 'landed', 60, ['tag3']);
			$first->sharedRules()
				->lead();
			$this->reloadProbe($group)
				->invalidateTags(['tag3']);
		};
		
		try
		{
			$value = $probe->get('fresh', fn(): string => 'computed', 60, queueLockTtlMs: 30000);
			
			return $value === 'computed'
				&& $probe->lookedWithLock === 1
				&& $probe->getClient()->exists($lockKey) === 0
				&& $this->guardStore()->setGroup($group)->get('fresh', queue: false) === 'computed';
		}
		finally
		{
			$probe->getClient()->del($lockKey);
			$this->reloadTearDown($first);
		}
	}
	
	/**
	 * RULE: the server's record of its newest invalidation only moves forward. Of two invalidations racing on
	 * one server, the later one's record stands even when the earlier one writes it last. A worker whose
	 * reload began between the two holds only the earlier rule, so it lacks the later one, and reloads.
	 */
	public function theServerMarkOnlyMovesForward(): bool
	{
		$this->requireSharedRules();
		
		[$writer, $group] = $this->keptAndGone();
		// the shared set grows old: the next worker reloads rather than adopt it
		$writer->sharedRules()
			->store($writer->rules(), microtime(true) * 1000 - 70000);
		$a = $this->probe(['rules_cache_ms' => 60000]);
		$a->setGroup($group);
		$b = $this->probe(['rules_cache_ms' => 60000]);
		$b->setGroup($group);
		$between = $this->probe(['rules_cache_ms' => 60000]);
		$between->setGroup($group);
		$shared = $a->sharedRules();
		$hooked = false;
		// a has appended its rule; before it writes its mark, another worker
		// reloads and b invalidates
		$shared->beforeWrite = static function(string $key) use ($shared, &$hooked, $between, $b): void
		{
			if($hooked
				|| $shared->isMark($key) === false)
			{
				return;
			}
			
			$hooked = true;
			$between->get('gone', queue: false);
			$b->invalidateTags(['gone']);
		};
		
		$a->invalidateTags(['x']);
		$shared->beforeWrite = null;
		$head = array_key_first((array)$a->getClient()->xRevRange($a->getRulesKey(), '+', '-', 1));
		$markId = $shared->writtenMark()[1] ?? null;
		$read = $between->get('gone', queue: false);
		$writer->clearPhysical();
		
		return $hooked
			&& $markId === $head
			&& $read === null
			&& count($between->fetchedFrom) === 2;
	}
	
	/**
	 * RULE: the reload flag and the server's record of its newest invalidation have no expiry in APCu,
	 * while the copy keeps its own. They change only through apcu_cas(), which keeps the expiry an entry
	 * was created with, so with one they would vanish a day after they were first written, however often
	 * they changed.
	 */
	public function theFlagAndTheMarkHaveNoExpiry(): bool
	{
		$this->requireSharedRules();
		
		$shared = new SharedRulesProbe('tests:expiry-' . bin2hex(random_bytes(4)));
		$nowMs = microtime(true) * 1000;
		$led = $shared->lead();
		$shared->written($nowMs, (int)$nowMs . '-0');
		$shared->written($nowMs + 1, (int)$nowMs . '-1');
		$stored = $shared->store(new Rules(3600000), $nowMs);
		$expiries = $shared->expiries();
		$shared->release();
		$shared->forget();
		
		return $led === true
			&& $stored === true
			&& $expiries === [
				'copy' => SharedRules::TTL_S,
				'lease' => 0,
				'mark-at' => 0,
				'mark-id' => 0,
			];
	}
	
	/**
	 * RULE: the server's record keeps a rule id exactly up to a sequence of 2^20 - 1 within its millisecond.
	 * Past that it records the next millisecond: later, never earlier. '0-0' stays '0-0', and an older
	 * invalidation lowers neither the time nor the id.
	 */
	public function theMarkKeepsEveryIdItCanAndNeverFallsBehindOne(): bool
	{
		$this->requireSharedRules();
		
		$shared = new SharedRulesProbe('tests:mark-' . bin2hex(random_bytes(4)));
		$nowMs = microtime(true) * 1000;
		$ms = (int)$nowMs;
		$shared->written($nowMs, Rules::NONE);
		$none = $shared->writtenMark()[1];
		$shared->written($nowMs, $ms . '-1048575');
		$fits = $shared->writtenMark()[1];
		$shared->written($nowMs, $ms . '-5000000');
		$past = $shared->writtenMark()[1];
		$shared->written($nowMs - 1000, $ms . '-7');
		$older = $shared->writtenMark();
		$shared->forget();
		
		return $none === Rules::NONE
			&& $fits === $ms . '-1048575'
			&& $past === ($ms + 1) . '-0'
			&& $older[0] >= $nowMs * 1000
			&& $older[1] === ($ms + 1) . '-0';
	}
	
	/**
	 * RULE: the server's record keeps the invalidation's time in microseconds. A copy whose fetch began a
	 * fraction of a millisecond after the invalidation was recorded is not held back, even when it lacks the
	 * rule (the stream lost it): it checks the read with no extra fetch. A whole millisecond, rounded up,
	 * would hold back every copy fetched within that millisecond, and each of its reads would reload.
	 */
	public function aCopyFetchedJustAfterAnInvalidationIsNotHeldBack(): bool
	{
		$this->requireSharedRules();
		
		[$invalidator, $group, $last] = $this->keptAndGone();
		$rules = $invalidator->rules();
		$shared = $invalidator->sharedRules();
		// A clean record for the server: an invalidation whose rule the stream no longer holds, and a copy
		// whose fetch began 0.3 ms after it, within the same millisecond.
		$shared->forget();
		$atMs = floor(microtime(true) * 1000) + 0.1;
		[$lastMs] = Rules::split($last);
		$shared->written($atMs, ($lastMs + 5) . '-0');
		$shared->store($rules, $atMs + 0.3);
		$worker = $this->probe(['rules_cache_ms' => 60000]);
		$worker->setGroup($group);
		
		$read = $worker->get('kept', queue: false);
		$invalidator->clearPhysical();
		
		return $read === 'kept'
			&& $worker->fetchedFrom === [];
	}
	
	/**
	 * RULE: with no shared copy, the instance's own record of its invalidation holds back its copy too:
	 * after a rule from elsewhere landed before its own, the copy lacks both, so the next read reloads and
	 * sees both
	 */
	public function withoutASharedCopyAnInstanceIsBehindItsOwnInvalidation(): bool
	{
		$group = 'reload-' . bin2hex(random_bytes(4));
		$store = $this->probe([
			'rules_cache_ms' => 60000,
			'rules_shared_cache' => false,
		]);
		$store->setGroup($group);
		$store->invalidateTags(['unrelated']);
		$store->set('kept', 'kept', 60, ['kept']);
		$store->set('gone', 'gone', 60, ['gone']);
		$last = $store->rules()
			->last();
		$store->getClient()
			->xAdd($store->getRulesKey(), '*', ['mode' => 'any', 'tags' => 'kept', 'first' => '0']);
		
		$store->invalidateTags(['gone']);
		$read = [
			$store->get('kept', queue: false),
			$store->get('gone', queue: false),
		];
		$store->clearPhysical();
		
		return $read === [null, null]
			&& $store->fetchedFrom === [Rules::NONE, $last];
	}
	
	/**
	 * RULE (probe): the invalidation's time is recorded after Redis added the rule. A worker whose reload
	 * began after the invalidation started, and read the stream before the rule was in it, counts as lacking
	 * the rule, and reloads.
	 */
	public function theMarkIsTakenAfterTheAppendReturned(): bool
	{
		$this->requireSharedRules();
		
		$group = 'reload-' . bin2hex(random_bytes(4));
		$writer = $this->probe(['rules_cache_ms' => 60000]);
		$writer->setGroup($group);
		$writer->invalidateTags(['unrelated']);
		$writer->set('item1', 'test', 60, ['tag1']);
		// nothing shared: the worker reloads
		$writer->sharedRules()
			->forget();
		/** @var RedisVersionedProbe $invalidator */
		$invalidator = $this->roundTripStore(RedisVersionedProbe::class, ['rules_cache_ms' => 60000]);
		$invalidator->setGroup($group);
		$worker = $this->probe(['rules_cache_ms' => 60000]);
		$worker->setGroup($group);
		$before = null;
		// after the invalidation took its moment, before its call
		$invalidator->getConnection()->counter()->beforeCall = static function() use ($worker, &$before): void
		{
			usleep(2000);
			$before = $worker->get('item1', queue: false);
		};
		
		$invalidator->invalidateTags(['tag1']);
		$read = $worker->get('item1', queue: false);
		$writer->clearPhysical();
		
		return $before === 'test'
			&& $read === null
			&& count($worker->fetchedFrom) === 2;
	}
	
	/**
	 * RULE: a copy an invalidation shares at once counts as fetched right before the invalidation call, not
	 * at the older fetch it was built on. A worker with rules_cache_ms of a second takes it as fresh (no
	 * XRANGE, no miss) and uses it.
	 */
	public function aSetSharedAtOnceIsStampedFromBeforeTheAppend(): bool
	{
		$this->requireSharedRules();
		
		[$first, $group] = $this->keptAndGone();
		// a set fetched five seconds ago, as a long request holds it
		$first->sharedRules()
			->store($first->rules(), microtime(true) * 1000 - 5000);
		$invalidator = $this->probe(['rules_cache_ms' => 60000]);
		$invalidator->setGroup($group);
		$invalidator->get('kept', queue: false);
		$invalidator->invalidateTags(['gone']);
		$worker = $this->probe(['rules_cache_ms' => 1000]);
		$worker->setGroup($group);
		$read = [
			$worker->get('kept', queue: false),
			$worker->get('gone', queue: false),
		];
		$first->clearPhysical();
		
		return $invalidator->fetchedFrom === []
			&& $invalidator->shares === 1
			&& $read === ['kept', null]
			&& $worker->fetchedFrom === [];
	}
	
	/**
	 * RULE: a copy that the reply does not prove complete keeps its fetch time. After a rule from elsewhere
	 * was added in between, the invalidation leaves the copy as it was. The copy shared when the instance
	 * goes away then claims no fetch time after that rule, which it lacks.
	 */
	public function aSetNotProvenCompleteKeepsItsStamp(): bool
	{
		$this->requireSharedRules();
		
		[$invalidator, $group] = $this->keptAndGone();
		// shared at once, then one shared when the instance goes away
		$invalidator->invalidateTags(['unrelated2']);
		$invalidator->invalidateTags(['kept2']);
		$held = $invalidator->rules()
			->last();
		$elsewhereMs = microtime(true) * 1000;
		$invalidator->getClient()
			->xAdd($invalidator->getRulesKey(), '*', ['mode' => 'any', 'tags' => 'other', 'first' => '0']);
		usleep(2000);
		$invalidator->invalidateTags(['gone']);
		$shared = $invalidator->sharedRules();
		
		unset($invalidator);
		gc_collect_cycles();
		[$rules, $fetchedAtMs] = $shared->load(2592000000);
		$this->reloadProbe($group)
			->clearPhysical();
		
		return $rules->last() === $held
			&& $fetchedAtMs < $elsewhereMs;
	}
	
	/**
	 * The reload rules' scene with the flag free (see reloadSetUp())
	 *
	 * @return array{0: RedisVersionedProbe, 1: string, 2: string, 3: float}
	 */
	protected function copyAged(
		float $ageMs,
	): array
	{
		$scene = $this->reloadSetUp($ageMs);
		$scene[0]->sharedRules()
			->release();
		
		return $scene;
	}
	
	/**
	 * An invalidator holding a set, in a group of its own: kept and gone
	 * written through, tagged so, its set shared, a mark of its own on the
	 * server. The invalidator, the group, the last id its set holds
	 *
	 * @return array{0: RedisVersionedProbe, 1: string, 2: string}
	 */
	protected function keptAndGone(): array
	{
		$group = 'reload-' . bin2hex(random_bytes(4));
		$invalidator = $this->probe(['rules_cache_ms' => 60000]);
		$invalidator->setGroup($group);
		// holding no set yet: a mark, nothing shared
		$invalidator->invalidateTags(['unrelated']);
		$invalidator->set('kept', 'kept', 60, ['kept']);
		$invalidator->set('gone', 'gone', 60, ['gone']);
		
		return [$invalidator, $group, $invalidator->rules()->last()];
	}
	
	/**
	 * Whether the stampede lock of $key is held, asked from inside its
	 * computation
	 */
	protected function lockHeld(
		RedisVersionedProbe $probe,
		string $key,
	): bool
	{
		$lockKey = $probe->getMemoLock()
			->getPrefixer()
			->prefix(MemoLock::TYPE_LOCK, $probe->itemId($key));
		
		return (int)$probe->getClient()->exists($lockKey) === 1;
	}
	
	/**
	 * How many lines $probe logged that hold $needle
	 */
	protected function countLogged(
		RedisVersionedProbe $probe,
		string $needle,
	): int
	{
		$count = 0;
		foreach($probe->logged as $event)
		{
			if(is_string($event[0] ?? null)
				&& str_contains($event[0], $needle))
			{
				$count++;
			}
		}
		
		return $count;
	}
	
	/**
	 * A fresh directory for a server's shared set (see SharedRulesInFiles)
	 */
	protected function rulesDirectory(): string
	{
		$directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'rules-' . bin2hex(random_bytes(6));
		mkdir($directory);
		
		return $directory;
	}
	
	protected function removeDirectory(
		string $directory,
	): void
	{
		foreach(glob($directory . DIRECTORY_SEPARATOR . '*') ?: [] as $file)
		{
			unlink($file);
		}
		rmdir($directory);
	}
	
	/**
	 * The reload rules' scene on a server of processes, its shared set in
	 * $directory: item1 tagged tag1, the set shared rules_cache_ms + $ageMs
	 * old as of $at (s), without the rule on tag1 the stream holds; the flag
	 * free. The group, the last id the set holds, the worker that built it
	 *
	 * @return array{0: string, 1: string, 2: RedisVersionedProbe}
	 */
	protected function processScene(
		string $directory,
		float $ageMs,
		float $at,
	): array
	{
		$group = 'reload-' . bin2hex(random_bytes(4));
		$first = $this->reloadProbe($group);
		$first->sharedRulesFactory = static fn(string $rulesKey, string $identity): SharedRulesProbe => new SharedRulesInFiles($rulesKey, $identity, $directory);
		$rulesKey = $first->getRulesKey();
		$client = $first->getClient();
		$client->xAdd($rulesKey, '*', ['mode' => 'any', 'tags' => 'tag2', 'first' => '1']);
		$first->set('item1', 'test', tags: ['tag1']);
		$first->get('item1', queue: false);
		$last = $first->rules()
			->last();
		
		$client->xAdd($rulesKey, '*', ['mode' => 'any', 'tags' => 'tag1', 'first' => '0']);
		$first->sharedRules()
			->store($first->rules(), $at * 1000 - self::RELOAD_CACHE_MS - $ageMs);
		
		return [$group, $last, $first];
	}
	
	/**
	 * Runs Worker.file.php once per $workers entry - name => [start delay ms,
	 * fetch delay ms, fetch fails, mode] - each reading item1 at $startAt (s)
	 * plus its delay, and waits for them all: their reports by name, null for
	 * one that left none
	 *
	 * @return array<array-key, array{value: mixed, attempts: int, ms: float}|null>
	 */
	protected function readInProcesses(
		string $group,
		string $directory,
		float $startAt,
		array $workers,
	): array
	{
		$script = __DIR__ . DIRECTORY_SEPARATOR . 'RedisVersioned' . DIRECTORY_SEPARATOR . 'Worker.file.php';
		$processes = [];
		foreach($workers as $name => $worker)
		{
			// a fresh opcache-less CLI, as the runner itself is in a sweep
			$process = proc_open([
				PHP_BINARY,
				'-d',
				'opcache.enable_cli=0',
				$script,
				$group,
				$directory,
				(string)$name,
				sprintf('%.6F', $startAt + $worker[0] / 1000),
				(string)self::RELOAD_CACHE_MS,
				$worker[3] ?? 'read',
				(string)$worker[1],
				$worker[2] ? '1' : '0',
			], [
				1 => ['file', $directory . DIRECTORY_SEPARATOR . $name . '.out', 'w'],
				2 => ['file', $directory . DIRECTORY_SEPARATOR . $name . '.err', 'w'],
			], $pipes);
			if(is_resource($process))
			{
				$processes[] = $process;
			}
		}
		foreach($processes as $process)
		{
			proc_close($process);
		}
		
		$reports = [];
		foreach($workers as $name => $worker)
		{
			$path = $directory . DIRECTORY_SEPARATOR . 'report-' . $name . '.json';
			$reports[$name] = is_file($path)
				? json_decode((string)file_get_contents($path), true)
				: null;
		}
		
		return $reports;
	}
	
	/**
	 * The plain write rules' scene, in a group of its own: k and k2 written
	 * through by a worker that reads the stream, and no set shared yet. The
	 * group, that worker
	 *
	 * @return array{0: string, 1: RedisVersionedProbe}
	 */
	protected function plainWriteScene(): array
	{
		$group = 'reload-' . bin2hex(random_bytes(4));
		$other = $this->probe([
			'rules_cache_ms' => 0,
			'rules_shared_cache' => false,
		]);
		$other->setGroup($group);
		$other->invalidateTags(['unrelated']);
		$other->set('k', 'old', 60, [self::GUARD_TAG]);
		$other->set('k2', 'old2', 60, [self::GUARD_TAG]);
		$this->reloadProbe($group)
			->sharedRules()
			->forget();
		
		return [$group, $other];
	}
	
	/**
	 * A hook that records an attempt by $name in $attempts - then fails it,
	 * unless $fails is false
	 */
	protected function recordAttempt(
		array &$attempts,
		string $name,
		bool $fails = true,
	): Closure
	{
		return static function() use (&$attempts, $name, $fails): void
		{
			$attempts[] = $name;
			if($fails)
			{
				throw new RedisException('the rules node does not answer');
			}
		};
	}
	
	/**
	 * The reload rules' scene, in a group of its own (no invalidation mark of
	 * another rule's): item1 tagged tag1, read and shared by a first worker;
	 * then another server invalidates tag1, the shared set is one fetched
	 * rules_cache_ms + $ageMs ago, and a worker holds the refresh flag. The
	 * first worker, the group, the last id the set holds, its fetch time
	 *
	 * @return array{0: RedisVersionedProbe, 1: string, 2: string, 3: float}
	 */
	protected function reloadSetUp(
		float $ageMs,
	): array
	{
		$group = 'reload-' . bin2hex(random_bytes(4));
		$first = $this->reloadProbe($group);
		$rulesKey = $first->getRulesKey();
		$client = $first->getClient();
		// a rule from elsewhere: the stream exists, no mark on this server
		$client->xAdd($rulesKey, '*', ['mode' => 'any', 'tags' => 'tag2', 'first' => '1']);
		$first->set('item1', 'test', tags: ['tag1']);
		$first->get('item1', queue: false);
		$last = $first->rules()
			->last();
		
		$client->xAdd($rulesKey, '*', ['mode' => 'any', 'tags' => 'tag1', 'first' => '0']);
		$fetchedAtMs = microtime(true) * 1000 - self::RELOAD_CACHE_MS - $ageMs;
		$shared = $first->sharedRules();
		$shared->store($first->rules(), $fetchedAtMs);
		$shared->lead();
		
		return [$first, $group, $last, $fetchedAtMs];
	}
	
	/**
	 * Another worker of the reload rules' server
	 */
	protected function reloadProbe(
		string $group,
	): RedisVersionedProbe
	{
		$probe = $this->probe(['rules_cache_ms' => self::RELOAD_CACHE_MS]);
		$probe->setGroup($group);
		
		return $probe;
	}
	
	protected function reloadTearDown(
		RedisVersionedProbe $first,
	): void
	{
		$first->sharedRules()
			?->release();
		$first->clearPhysical();
	}
	
	/**
	 * A probe holding no rules (none shared either) whose first read of the
	 * stream fails - readable again for whatever follows it
	 */
	protected function rulesFailingOnceProbe(): RedisVersionedProbe
	{
		$probe = $this->probe(['rules_shared_cache' => false]);
		$thrown = 0;
		$probe->beforeRuleFetch = static function() use (&$thrown): void
		{
			if($thrown++ === 0)
			{
				throw new RedisException('the rules stream cannot be read');
			}
		};
		
		return $probe;
	}
	
	/**
	 * A probe holding no rules (none shared either) whose reads of the stream
	 * fail while $failing says so
	 */
	protected function rulesFailingProbe(
		bool &$failing,
	): RedisVersionedProbe
	{
		$probe = $this->probe(['rules_shared_cache' => false]);
		$probe->beforeRuleFetch = static function() use (&$failing): void
		{
			if($failing)
			{
				throw new RedisException('the rules stream cannot be read');
			}
		};
		
		return $probe;
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
	 * A fresh store whose client counts its round trips (TraitBatch)
	 */
	protected function batchProbe(): ?KeyValueRedis
	{
		return $this->roundTripStore(Store::class);
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
