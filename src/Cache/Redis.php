<?php
declare(strict_types=1);

namespace Ovos\Cache;

use Ovos\ArrayObject;
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
	protected $_config;

	/**
	 * Redis object
	 *
	 * @var BaseRedis|null
	 */
	protected $_client = null;

	/**
	 * @var RedisCachePool
	 */
	protected $_pool;

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
		$timeout = (int)($this->_config->timeout ?? 1); // in seconds
		$readTimeout = (int)($this->_config->read_timeout ?? $timeout);
		$port = (int)($this->_config->port ?? 6379);

		$connectionOptions = [
			BaseRedis::OPT_READ_TIMEOUT => $readTimeout,
			BaseRedis::OPT_SERIALIZER => BaseRedis::SERIALIZER_NONE,
		];

		$this->_client = new BaseRedis;
		// connect
		// suspend connection errors with @ since it triggers a warning when it cannot connect...
		$connectionStatus = @$this->_client->connect($this->_config->host, $this->_config->port, $this->_config->timeout);

		if($connectionStatus === false)
		{
			$this->_client = null;
			services()->events->log('Could not connect to cache server "%s"', $this->_config->host);
		}

		$this->_client->select($this->_config->database);

		return $connectionStatus;
	}

	/**
	 * @return null|BaseRedis
	 */
	public function getClient(): ?BaseRedis
	{
		return $this->_client;
	}

	/**
	 * @return RedisCachePool
	 */
	public function getCachePool(): RedisCachePool
	{
		if($this->_pool === null)
		{
			$this->_pool = new RedisCachePool($this->_client, $this->_config);
		}

		return $this->_pool;
	}
}