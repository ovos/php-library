<?php
declare(strict_types=1);

namespace Ovos\Cache;

use Ovos\ArrayObject;
use Ovos\Redis\Connection;
use Redis as BaseRedis;
use Ovos\Cache\Adapter\Redis\RedisCachePool;
use function Ovos\services;

/**
 * Cache
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
class Redis
{
	/**
	 * @var ArrayObject
	 */
	protected ArrayObject $_config;

	/**
	 * Redis connection
	 *
	 * @var null|Connection
	 */
	protected null|Connection $_connection;

	/**
	 * @var RedisCachePool
	 */
	protected RedisCachePool $_pool;

	/**
	 * @param ArrayObject $config
	 */
	public function __construct($config)
	{
		$this->setConfig($config);
	}

	/**
	 * @param ArrayObject $config
	 * 
	 * @return $this
	 */
	public function setConfig(ArrayObject $config): self
	{
		$this->_config = $config;
		
		return $this;
	}

	/**
	 * @return ArrayObject
	 */
	public function getConfig(): ArrayObject
	{
		return $this->_config;
	}

	/**
	 * @return bool
	 */
	public function connect(): bool
	{
		$this->_connection = new Connection($this->_config);
		return $this->_connection->connect();
	}

	/**
	 * @return null|BaseRedis
	 */
	public function getClient(): ?BaseRedis
	{
		return $this->_connection->getClient();
	}

	/**
	 * @return RedisCachePool
	 */
	public function getCachePool(): RedisCachePool
	{
		if($this->_pool === null)
		{
			$this->_pool = new RedisCachePool($this->getClient(), $this->_config);
		}

		return $this->_pool;
	}
}
