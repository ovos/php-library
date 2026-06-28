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
