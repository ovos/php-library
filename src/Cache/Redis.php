<?php
declare(strict_types=1);

namespace Ovos\Cache;

use Ovos\ArrayObject;
use Redis as BaseRedis;
use Cache\Adapter\Redis\RedisCachePool;
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
	 *
	 * @return bool
	 */
	public function connect(ArrayObject $config): bool
	{
		$timeout = (int)($config->timeout ?? 1); // in seconds
		$readTimeout = (int)($config->read_timeout ?? $timeout);
		$port = (int)($config->port ?? 6379);

		$connectionOptions = [
			BaseRedis::OPT_READ_TIMEOUT => $readTimeout,
			BaseRedis::OPT_SERIALIZER => BaseRedis::SERIALIZER_NONE,
		];

		$this->_client = new BaseRedis();
		// connect
		// suspend connection errors with @ since it triggers a warning when it cannot connect...
		$connectionStatus = @$this->_client->connect($config->host, $config->port, $config->timeout);

		if($connectionStatus === false)
		{
			$this->_client = null;
			services()->events->log('Could not connect to cache server "%s"', $config->host);
		}

		$this->_client->select($config->database);

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
			$this->_pool = new RedisCachePool($this->_client);
		}

		return $this->_pool;
	}
}