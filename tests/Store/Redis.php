<?php
declare(strict_types=1);

namespace Tests\Store;

use Ovos\ArrayObject;
use Ovos\Redis\Connection;
use Ovos\Store\Cache;
use Ovos\Store\Redis as RedisStore;
use Ovos\Test;

use function Ovos\config;

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
	
	public function storeArray(): bool
	{
		$this->initStore();
		$array = [
			'stored' => true,
		];
		
		$this->_store->set('array', $array);
		$array = $this->_store->get('array');
		
		return $array['stored'] === true;
	}
	
	/*
	public function invalidateTags(): bool
	{
		$this->initStore();
		$this->_store->set('tags', 'test', tags: ['tag1', 'tag2']);
		$this->_store->invalidateTags(['tag1']);
		
		$result = $this->_store->get('tags');
		
		return $result === null;
	}
	*/
	
	public function clear(): bool
	{
		$this->initStore();
		$array = [
			'stored' => true,
		];
		
		$this->_store->set('array', $array);
		$this->_store->clear();
		$result = $this->_store->get('array');
		
		return $result === null;
	}
}
