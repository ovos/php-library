<?php
declare(strict_types=1);

namespace Tests\Cache\Store;

use Ovos\Cache\Store\Redisearch as Store;
use Ovos\Test;
use Ovos\Test\Internal;
use Ovos\Test\Cache\Store\TraitRedis;
use Override;

/**
 * Redisearch
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Redisearch extends Test
{
	use TraitRedis;
	
	public const string KEY_ITEM = 'item';
	
	protected ?Store $store = null;
	
	public function __construct()
	{
		$this->store = $this->getStore(Store::class);
		$this->store->getConnection()->getConfig()->database = 0;
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
		$this->store->indexRebuild();
		
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
		$this->store->indexRebuild();
		
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
		$this->store->indexRebuild();
		
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
	
	public function clear(): bool
	{
		$this->store->indexRebuild();
		
		$array = [
			'stored' => true
		];
		
		$this->store->set(self::KEY_ITEM, $array);
		$this->store->clear();
		$result = $this->store->get(self::KEY_ITEM, queue: false);
		
		return $result === null;
	}
	
	/**
	 * Called by the runner after each test method
	 */
	#[Internal]
	#[Override]
	public function finalize(): void
	{
		$this->store->clear();
		$this->store->indexDrop($this->store->getType());
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
