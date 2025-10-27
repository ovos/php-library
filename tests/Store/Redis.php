<?php
declare(strict_types=1);

namespace Tests\Store;

use Ovos\ArrayObject;
use Ovos\Redis\Connection;
use Ovos\Store\Cache;
use Ovos\Store\Redis as RedisStore;
use Ovos\Test;
use Ovos\Test\Internal;
use RedisException;

use function Ovos\config;
use function sprintf;
use function count;
use function array_diff;

/**
 * Redis
 *
 * @package Tests
 * @author Marcin Gil <mg@ovos.at>
 */
class Redis extends Test
{
	/**
	 * @var string
	 */
	public const string KEY_ITEM = 'item';
	
	/**
	 * @var ArrayObject
	 */
	protected ArrayObject $_config;
	
	/**
	 * @var ?Connection
	 */
	protected ?Connection $_connection = null;
	
	/**
	 * @var ?RedisStore
	 */
	protected ?RedisStore $_store = null;
	
	public function __construct()
	{
		$this->_config = config()->cache;
		
		$this->_connection = new Connection($this->_config->persistent);
		if($this->_connection->connect() === false)
		{
			throw new RedisException
			(
				sprintf('Could not connect to redis server "%s" on port "%s".',
					$this->_store->getConfig()->host,
					$this->_store->getConfig()->port,
				)
			);
		}
	}
	
	protected function _initStore(): void
	{
		$this->_store = new RedisStore
		(
			$this->_connection,
			$this->_config->persistent,
			$this->_config->prefix,
			Cache::GROUP_TESTS,
		);
	}
	
	/**
	 * Called by the runner before each test method
	 */
	#[Internal]
	public function prepare(): void
	{
		$this->_initStore();
	}
	
	public function store(): bool
	{
		return true;
	}
	
	public function delete(): bool
	{
		$this->_store->set(self::KEY_ITEM, 'test');
		$this->_store->delete(self::KEY_ITEM);
		
		$exists = $this->_store->get(self::KEY_ITEM, queue: false);
		
		return $exists === null;
	}
	
	public function storeArray(): bool
	{
		$array = [
			'stored' => true,
		];
		
		$this->_store->set(self::KEY_ITEM, $array);
		$array = $this->_store->get(self::KEY_ITEM, queue: false);
		
		try
		{
			return $array['stored'] === true;
		}
		finally
		{
			$this->_store->delete(self::KEY_ITEM);
		}
	}
	
	public function getAllTags(): bool
	{
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
		$tags1 = ['tag1', 'tag2'];
		$tags2 = ['tag3'];
		
		$this->_store->set('item1', 'test', tags: $tags1);
		$this->_store->set('item2', 'test', tags: $tags2);
		$this->_store->set('item3', 'test', tags: $tags1);
		
		// may return more ids (the list was not garbage collected
		$ids = $this->_store->getIdsMatchingAnyTags(['tag1']);
		
		try
		{
			return count(array_diff(['item1', 'item3'], $ids)) === 0; // all the items are present in $ids
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
		$tags = ['tag1', 'tag2'];
		$newTags = ['tag1', 'tag2', 'tag3'];
		
		$this->_store->set(self::KEY_ITEM, 'test', tags: $tags);
		$this->_store->set(self::KEY_ITEM, 'test', tags: $newTags);
		
		$result = $this->_store->getTags(self::KEY_ITEM);
		
		try
		{
			return $result === $newTags; // have the same key/value pairs in the same order and of the same types.
		}
		finally
		{
			$this->_store->delete(self::KEY_ITEM);
		}
	}
	
	public function removeTags(): bool
	{
		$tags = ['tag1', 'tag2', 'tag3'];
		$newTags = ['tag1', 'tag2'];
		
		$this->_store->set(self::KEY_ITEM, 'test', tags: $tags);
		$this->_store->set(self::KEY_ITEM, 'test', tags: $newTags);
		
		$result = $this->_store->getTags(self::KEY_ITEM);
		
		try
		{
			return $result === $newTags; // have the same key/value pairs in the same order and of the same types.
		}
		finally
		{
			$this->_store->delete(self::KEY_ITEM);
		}
	}
	
	public function invalidateTags(): bool
	{
		$tags = ['tag1', 'tag2'];
		$this->_store->set(self::KEY_ITEM, 'test', tags: $tags);
		$this->_store->invalidateTags([$tags[0]]);
		
		$result = $this->_store->get(self::KEY_ITEM, queue: false);
		
		try
		{
			return $result === null;
		}
		finally
		{
			$this->_store->delete(self::KEY_ITEM);
		}
	}
	
	public function cleanTags(): bool
	{
		$this->_store->setCleanTags(true);
		
		$tags = ['tag1', 'tag2'];
		$this->_store->set(self::KEY_ITEM, 'test', tags: $tags);
		$this->_store->invalidateTags([$tags[0]]);
		
		// check if the ID still exists within the tag field
		$tagId = $this->_store->prefix($tags[1], $this->_store->getType($this->_store::TYPE_TAGS));
		$exists = $this->_store->getClient()->hGet($tagId, self::KEY_ITEM);
		
		try
		{
			return $exists === false;
		}
		finally
		{
			$this->_store->delete(self::KEY_ITEM);
		}
	}
	
	public function collectGarbage(): bool
	{
		$tags = ['tag1'];
		
		$this->_store->set(self::KEY_ITEM, 'test', tags: $tags);
		
		// unlink the ID, leaving only the tag field with the key of the item in it
		$id = $this->_store->prefix(self::KEY_ITEM, $this->_store->getType());
		$this->_store->getClient()->unlink($id);
		
		$this->_store->collectGarbage();
		
		// check if the ID still exists within the tag field
		$tagId = $this->_store->prefix($tags[0], $this->_store->getType($this->_store::TYPE_TAGS));
		$exists = $this->_store->getClient()->hGet($tagId, self::KEY_ITEM);
		
		try
		{
			return $exists === false;
		}
		finally
		{
			$this->_store->delete(self::KEY_ITEM);
		}
	}
	
	public function clear(): bool
	{
		$array = [
			'stored' => true,
		];
		
		$this->_store->set(self::KEY_ITEM, $array);
		$this->_store->clear();
		$result = $this->_store->get(self::KEY_ITEM, queue: false);
		
		return $result === null;
	}
	
	/**
	 * Called by the runner after all test methods have been invoked
	 */
	#[Internal]
	public function deconstruct(): void
	{
		$this->_connection->disconnect();
	}
}
