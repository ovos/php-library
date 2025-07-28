<?php
declare(strict_types=1);

namespace Tests\Store;

use Ovos\ArrayObject;
use Ovos\Redis\Connection;
use Ovos\Store\Cache;
use Ovos\Store\Redisearch as RedisStore;
use Ovos\Test;
use RedisException;
use ReflectionClass;

use function Ovos\config;
use function sprintf;

/**
 * Redisearch
 *
 * @package Tests
 * @author Marcin Gil <mg@ovos.at>
 */
class Redisearch extends Test
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
		
		$storeClass = $this->_config->persistent->store;
		$currentClass = (new ReflectionClass($this))->getShortName();
		if($storeClass !== $currentClass)
		{
			$this->setIsDisabled(true,
				sprintf('"store" is set to "%s".', $storeClass)
			);
		}
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
		$key = 'item';
		
		$this->_store->set($key, 'test');
		$this->_store->delete($key);
		
		$exists = $this->_store->get($key);
		
		return $exists === null;
	}
	
	public function storeArray(): bool
	{
		$this->_store->indexRebuild();
		
		$key = 'item';
		$array = [
			'stored' => true
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
	
	public function invalidateTags(): bool
	{
		$this->_store->indexRebuild();
		
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
	
	public function clear(): bool
	{
		$this->_store->indexRebuild();
		
		$key = 'item';
		$array = [
			'stored' => true
		];
		
		$this->_store->set($key, $array);
		$this->_store->clear();
		$result = $this->_store->get($key);
		
		return $result === null;
	}
}
