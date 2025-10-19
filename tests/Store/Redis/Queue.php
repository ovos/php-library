<?php
declare(strict_types=1);

namespace Tests\Store\Redis;

use Ovos\ArrayObject;
use Ovos\Redis\Connection;
use Ovos\Store\Cache;
use Ovos\Store\Redis as RedisStore;
use Ovos\Test;
use Ovos\Test\Internal;

use function Ovos\config;
use function sprintf;

/**
 * Queue
 *
 * @package Tests
 * @author Marcin Gil <mg@ovos.at>
 */
class Queue extends Test
{
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
			$this->_config->prefix,
			$this->_connection,
			$this->_config->persistent,
			Cache::GROUP_TESTS
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
	
	public function set(): bool
	{
		$key = 'item';
		
		try
		{
			if($this->_store->get($key, willSet: true) === null)
			{
				$this->_store->set($key, 'test');
			}
			
			$exists = $this->_store->get($key);
			
			return $exists !== null;
		}
		finally
		{
			$this->_store->delete($key);
		}
	}
	
	public function setCallback(): bool
	{
		$key = 'item';
		$value = 'test';
		
		try
		{
			$result = $this->_store->get($key,
				setCallback: fn() => $value,
			);
			
			return $result === $value;
		}
		finally
		{
			$this->_store->delete($key);
		}
	}
	
	public function setCallbackWithTags(): bool
	{
		$key = 'item';
		$value = 'test';
		$tags = ['tag1', 'tag2'];
		
		try
		{
			$value = $this->_store->get($key,
				setCallback: fn() => $value,
				tags: $tags
			);
			
			$result = $this->_store->getTags($key);
			
			return $result === $tags; // have the same key/value pairs in the same order and of the same types.
		}
		finally
		{
			$this->_store->delete($key);
		}
	}
	
	public function releaseActiveLock(): bool
	{
		$key = 'item';
		
		try
		{
			if($this->_store->get($key, willSet: true) === null)
			{
				$this->_store->releaseActiveLock($key);
			}
			
			$lockKey = $this->_store
				->prefix(RedisStore::KEY_LOCK, $key);
			
			return $this->_store->getClient()
				->exists($lockKey) === 0;
		}
		finally
		{
			$this->_store->delete($key);
		}
	}
	
	public function renewLock(): bool
	{
		$key = 'item';
		
		try
		{
			if($this->_store->get($key, willSet: true) === null)
			{
				$this->_store->renewLock($key);
			}
			
			return true;
		}
		finally
		{
			$this->_store->delete($key);
		}
	}
	
	/**
	 * Called by the runner after all test methods have been invoked
	 */
	#[Internal]
	public function deconstruct(): void
	{
		$this->_store->clear();
		$this->_connection->disconnect();
	}
}
