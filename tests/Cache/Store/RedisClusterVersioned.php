<?php
declare(strict_types=1);

namespace Tests\Cache\Store;

use Ovos\Cache\Store\RedisClusterVersioned as Store;
use Ovos\Test;
use Ovos\Test\Internal;
use Ovos\Test\Cache\Store\TraitRedisCluster;
use Override;

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
		$this->store?->clearPhysical();
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
}
