<?php
declare(strict_types=1);

namespace Tests\Cache\Store;

use Ovos\Cache\Store\Redis as Store;
use Ovos\Test;
use Ovos\Test\Internal;
use Ovos\Test\Cache\Store\TraitRedis;
use Override;

use function count;
use function array_diff;
use function in_array;

/**
 * Redis
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Redis extends Test
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
			'stored' => true,
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
	
	public function getAllTags(): bool
	{
		$tags1 = ['tag1', 'tag2'];
		$tags2 = ['tag3'];
		
		$this->store->set('item1', 'test', tags: $tags1);
		$this->store->set('item2', 'test', tags: $tags2);
		$this->store->set('item3', 'test', tags: $tags1);
		
		$tags = $this->store->getAllTags();
		sort($tags);
		
		try
		{
			return $tags === ['tag1', 'tag2', 'tag3'];
		}
		finally
		{
			$this->store->delete('item1');
			$this->store->delete('item2');
			$this->store->delete('item3');
		}
	}
	
	public function getIdsMatchingAnyTags(): bool
	{
		$tags1 = ['tag1', 'tag2'];
		$tags2 = ['tag3'];
		
		$this->store->set('item1', 'test', tags: $tags1);
		$this->store->set('item2', 'test', tags: $tags2);
		$this->store->set('item3', 'test', tags: $tags1);
		
		// may return more ids (the list was not garbage collected
		$ids = $this->store->getIdsMatchingAnyTags(['tag1']);
		
		try
		{
			// all the items are present in $ids
			return count(array_diff(['item1', 'item3'], $ids)) === 0;
		}
		finally
		{
			$this->store->delete('item1');
			$this->store->delete('item2');
			$this->store->delete('item3');
		}
	}
	
	public function getIdsMatchingAllTags(): bool
	{
		$this->store->set('item1', 'test', tags: ['tag1', 'tag2']);
		$this->store->set('item2', 'test', tags: ['tag1']);
		$this->store->set('item3', 'test', tags: ['tag2', 'tag1']);
		
		// may return more ids (the list was not garbage collected)
		$ids = $this->store->getIdsMatchingAllTags(['tag1', 'tag2']);
		
		try
		{
			// items having all the tags are present in $ids, other items are not
			return count(array_diff(['item1', 'item3'], $ids)) === 0
				&& in_array('item2', $ids, true) === false;
		}
		finally
		{
			$this->store->delete('item1');
			$this->store->delete('item2');
			$this->store->delete('item3');
		}
	}
	
	public function addTags(): bool
	{
		$tags = ['tag1', 'tag2'];
		$newTags = ['tag1', 'tag2', 'tag3'];
		
		$this->store->set(self::KEY_ITEM, 'test', tags: $tags);
		$this->store->set(self::KEY_ITEM, 'test', tags: $newTags);
		
		$result = $this->store->getTags(self::KEY_ITEM);
		
		try
		{
			return $result === $newTags; // have the same key/value pairs in the same order and of the same types.
		}
		finally
		{
			$this->store->delete(self::KEY_ITEM);
		}
	}
	
	public function removeTags(): bool
	{
		$tags = ['tag1', 'tag2', 'tag3'];
		$newTags = ['tag1', 'tag2'];
		
		$this->store->set(self::KEY_ITEM, 'test', tags: $tags);
		$this->store->set(self::KEY_ITEM, 'test', tags: $newTags);
		
		$result = $this->store->getTags(self::KEY_ITEM);
		
		try
		{
			return $result === $newTags; // have the same key/value pairs in the same order and of the same types.
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
		$this->store->setCleanTags(false);
		
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
	
	public function cleanTags(): bool
	{
		$this->store->setCleanTags(true);
		
		$tags = ['tag1', 'tag2'];
		$this->store->set(self::KEY_ITEM, 'test', tags: $tags);
		$this->store->invalidateTags([$tags[0]]);
		
		// check if the ID still exists within the tag field
		$tagId = $this->store
			->prefix($tags[1], $this->store->getType($this->store::TYPE_TAGS));
		$exists = $this->store->getClient()
			->hGet($tagId, self::KEY_ITEM);
		
		try
		{
			return $exists === false;
		}
		finally
		{
			$this->store->delete(self::KEY_ITEM);
		}
	}
	
	public function cleanTagsMatchingAll(): bool
	{
		$this->store->setCleanTags(true);
		
		$tags = ['tag1', 'tag2', 'tag3'];
		$this->store->set(self::KEY_ITEM, 'test', tags: $tags);
		$this->store->invalidateTags(['tag1', 'tag2'], Store::MATCHING_ALL);
		
		// check if the ID was removed from the tag field,
		// also within the tag which was not a part of the invalidation
		$tagId = $this->store
			->prefix($tags[2], $this->store->getType($this->store::TYPE_TAGS));
		$exists = $this->store->getClient()
			->hGet($tagId, self::KEY_ITEM);
		
		try
		{
			return $exists === false;
		}
		finally
		{
			$this->store->delete(self::KEY_ITEM);
		}
	}
	
	public function collectGarbage(): bool
	{
		$tags = ['tag1'];
		
		$this->store->set(self::KEY_ITEM, 'test', tags: $tags);
		
		// unlink the ID, leaving only the tag field with the key of the item in it
		$id = $this->store
			->prefix(self::KEY_ITEM, $this->store->getType());
		$this->store->getClient()
			->unlink($id);
		
		$this->store->collectGarbage();
		
		// check if the ID still exists within the tag field
		$tagId = $this->store
			->prefix($tags[0], $this->store->getType($this->store::TYPE_TAGS));
		$exists = $this->store
			->getClient()
				->hGet($tagId, self::KEY_ITEM);
		
		try
		{
			return $exists === false;
		}
		finally
		{
			$this->store->delete(self::KEY_ITEM);
		}
	}
	
	public function clear(): bool
	{
		$array = [
			'stored' => true,
		];
		
		$this->store->set(self::KEY_ITEM, $array);
		$this->store->clear();
		$result = $this->store->get(self::KEY_ITEM, queue: false);
		
		return $result === null;
	}
	
	public function number(): bool
	{
		$this->store->set(self::KEY_ITEM, 1);
		
		try
		{
			return $this->store->get(self::KEY_ITEM, queue: false) === '1';
		}
		finally
		{
			$this->store->delete(self::KEY_ITEM);
		}
	}
	
	/**
	 * Called by the runner after each test method
	 */
	#[Internal]
	#[Override]
	public function finalize(): void
	{
		$this->store->clear();
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
