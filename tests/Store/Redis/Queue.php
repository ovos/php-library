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
	
	public function queue(): bool
	{
		$key = 'item';
		
		try
		{
			if($this->_store->get($key, queue: true) === null)
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
	
	/**
	 * Called by the runner after all test methods have been invoked
	 */
	#[Internal]
	public function deconstruct(): void
	{
		$this->_connection->disconnect();
	}
}
