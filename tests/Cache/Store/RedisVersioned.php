<?php
declare(strict_types=1);

namespace Tests\Cache\Store;

use Ovos\Cache\Store\RedisVersioned as Store;
use Ovos\Cache\Versioned\Rules;
use Ovos\Cache\Versioned\SharedRules;
use Ovos\Test;
use Ovos\Test\Internal;
use Ovos\Test\Cache\Store\RedisVersionedProbe;
use Ovos\Test\Cache\Store\TraitRedis;
use Ovos\Test\Exception\SkipException;
use Override;

use function count;
use function microtime;
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
}
