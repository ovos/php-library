<?php
declare(strict_types=1);

namespace Tests\Store;

use Ovos\ArrayObject;
use Ovos\Redis\Connection;
use Ovos\Store\Cache;
use Ovos\Store\Redis as RedisStore;
use Ovos\Test;

use function Ovos\config;
use function sprintf;

/**
 * Redis
 *
 * @package Tests
 * @author Marcin Gil <mg@ovos.at>
 */
class Redis extends Test
{
	/**
	 * @var ArrayObject
	 */
	protected ArrayObject $_config;
	
	/**
	 * @var ?RedisStore
	 */
	protected ?RedisStore $_store = null;
	
	public function __construct()
	{
		$this->_config = config()->cache;
	}
	
	public function initStore(): bool
	{
		$connection = new Connection($this->_config->persistent);
		if($connection->connect() === false)
		{
			throw new RedisException
			(
				sprintf('Could not connect to redis server "%s" on port "%s".',
					$this->_store->getConfig()->host,
					$this->_store->getConfig()->port,
				)
			);
		}
		
		$this->_store = new RedisStore
		(
			$this->_config->prefix,
			$connection,
			$this->_config->persistent,
			Cache::GROUP_TESTS
		);
		
		return true;
	}
	
	public function delete(): bool
	{
		$this->initStore();
		
		$key = 'item';
		
		$this->_store->set($key, 'test');
		$this->_store->delete($key);
		
		$exists = $this->_store->get($key);
		
		return $exists === null;
	}
	
	public function storeArray(): bool
	{
		$this->initStore();
		
		$key = 'item';
		$array = [
			'stored' => true,
		];
		
		$this->_store->set($key, $array);
		$array = $this->_store->get($key);
		
		try
		{
			return $array['stored'] === true;
		}
		finally
		{
			$this->_store->delete($key);
		}
	}
	
	public function getAllTags(): bool
	{
		$this->initStore();
		
		$tags1 = ['tag1', 'tag2'];
		$tags2 = ['tag3'];
		
		$this->_store->set('item1', 'test', tags: $tags1);
		$this->_store->set('item2', 'test', tags: $tags2);
		$this->_store->set('item3', 'test', tags: $tags1);
		
		$tags = $this->_store->getAllTags();
		sort($tags);
		
		try
		{
			return $tags === ['tag1', 'tag2', 'tag3'];
		}
		finally
		{
			$this->_store->delete('item1');
			$this->_store->delete('item2');
			$this->_store->delete('item3');
		}
	}
	
	public function getIdsMatchingAnyTags(): bool
	{
		$this->initStore();
		
		$tags1 = ['tag1', 'tag2'];
		$tags2 = ['tag3'];
		
		$this->_store->set('item1', 'test', tags: $tags1);
		$this->_store->set('item2', 'test', tags: $tags2);
		$this->_store->set('item3', 'test', tags: $tags1);
		
		$ids = $this->_store->getIdsMatchingAnyTags(['tag1']);
		
		try
		{
			return $ids === ['item1', 'item3'];
		}
		finally
		{
			$this->_store->delete('item1');
			$this->_store->delete('item2');
			$this->_store->delete('item3');
		}
	}
	
	public function addTags(): bool
	{
		$this->initStore();
		
		$key = 'item';
		$tags = ['tag1', 'tag2'];
		$newTags = ['tag1', 'tag2', 'tag3'];
		
		$this->_store->set($key, 'test', tags: $tags);
		$this->_store->set($key, 'test', tags: $newTags);
		
		$result = $this->_store->getTags($key);
		
		try
		{
			return $result === $newTags; // have the same key/value pairs in the same order and of the same types.
		}
		finally
		{
			$this->_store->delete($key);
		}
	}
	
	public function removeTags(): bool
	{
		$this->initStore();
		
		$key = 'item';
		$tags = ['tag1', 'tag2', 'tag3'];
		$newTags = ['tag1', 'tag2'];
		
		$this->_store->set($key, 'test', tags: $tags);
		$this->_store->set($key, 'test', tags: $newTags);
		
		$result = $this->_store->getTags($key);
		
		try
		{
			return $result === $newTags; // have the same key/value pairs in the same order and of the same types.
		}
		finally
		{
			$this->_store->delete($key);
		}
	}
	
	public function invalidateTags(): bool
	{
		$this->initStore();
		
		$key = 'item';
		$tags = ['tag1', 'tag2'];
		$this->_store->set($key, 'test', tags: $tags);
		$this->_store->invalidateTags([$tags[0]]);
		
		$result = $this->_store->get($key);
		
		try
		{
			return $result === null;
		}
		finally
		{
			$this->_store->delete($key);
		}
	}
	
	public function cleanTags(): bool
	{
		$this->initStore();
		$this->_store->setCleanTags(true);
		
		$key = 'item';
		$tags = ['tag1', 'tag2'];
		$this->_store->set($key, 'test', tags: $tags);
		$this->_store->invalidateTags([$tags[0]]);
		
		// check if the ID still exists within the tag field
		$tagId = $this->_store->prefix($tags[1], $this->_store->getType($this->_store::TYPE_TAGS));
		$exists = $this->_store->getClient()->hGet($tagId, $key);
		
		try
		{
			return $exists === false;
		}
		finally
		{
			$this->_store->delete($key);
		}
	}
	
	public function collectGarbage(): bool
	{
		$this->initStore();
		
		$key = 'item';
		$tags = ['tag1'];
		
		$this->_store->set($key, 'test', tags: $tags);
		
		// unlink the ID, leaving only the tag field with the key of the item in it
		$id = $this->_store->prefix($key, $this->_store->getType());
		$this->_store->getClient()->unlink($id);
		
		$this->_store->collectGarbage();
		
		// check if the ID still exists within the tag field
		$tagId = $this->_store->prefix($tags[0], $this->_store->getType($this->_store::TYPE_TAGS));
		$exists = $this->_store->getClient()->hGet($tagId, $key);
		
		try
		{
			return $exists === false;
		}
		finally
		{
			$this->_store->delete($key);
		}
	}
	
	public function clear(): bool
	{
		$this->initStore();
		
		$key = 'item';
		$array = [
			'stored' => true,
		];
		
		$this->_store->set($key, $array);
		$this->_store->clear();
		$result = $this->_store->get($key);
		
		return $result === null;
	}
}
