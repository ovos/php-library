<?php
declare(strict_types=1);

namespace Ovos\Redis;

use Ovos\ArrayObject;
use Redis as BaseRedis;
use RedisException;

use function Ovos\services;

/**
 * Connection
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
class Connection
{
	/**
	 * @var ArrayObject
	 */
	protected ArrayObject $_config;
	
	/**
	 * Redis object
	 *
	 * @var ?BaseRedis
	 */
	protected ?BaseRedis $_client = null;
	
	/**
	 * @param ArrayObject $config
	 */
	public function __construct(ArrayObject $config)
	{
		$this->setConfig($config);
	}
	
	/**
	 * @param ArrayObject $config
	 * 
	 * @return self
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
		$port = (int)($this->_config->port ?? 6379);
		$connectTimeout = (int)($this->_config->connect_timeout ?? $this->_config->timeout ?? 1); // in seconds
		$readTimeout = (int)($this->_config->read_timeout ?? $connectTimeout);
		
		$connectionOptions = [
			'host' => $this->_config->host,
			'port' => $port,
			'connectTimeout' => $connectTimeout,
		];
		$this->_client = new BaseRedis($connectionOptions);
		
		$options = [
			BaseRedis::OPT_READ_TIMEOUT => $readTimeout,
			BaseRedis::OPT_SERIALIZER => BaseRedis::SERIALIZER_NONE,
			BaseRedis::OPT_REPLY_LITERAL => true, // https://github.com/phpredis/phpredis/issues/1550
			BaseRedis::OPT_MAX_RETRIES => 0, // do not limit the max retries, let the timeout handle it
			BaseRedis::OPT_BACKOFF_ALGORITHM => BaseRedis::BACKOFF_ALGORITHM_DECORRELATED_JITTER, // https://github.com/phpredis/phpredis/pull/1993/files
			BaseRedis::OPT_BACKOFF_BASE => 500, // the minimum delay between retries when backing off
			BaseRedis::OPT_BACKOFF_CAP => 750, // the maximum delay between replies when backing off
		];
		
		// set options
		foreach($options as $optionName => $optionValue)
		{
			$this->_client->setOption($optionName, $optionValue);
		}
		
		try
		{
			$this->_client->select($this->_config->database);
		}
		catch(RedisException $exception)
		{
			$this->_client = null;
			services()->events->log
			(
				new RedisException
				(
					sprintf('Could not connect to redis server "%s" on port "%s".',
						$this->_config->host,
						$this->_config->port
					), 
					0,
					$exception, // previous
				)
			);
			
			return false;
		}
		
		return true;
	}
	
	/**
	 * @return ?BaseRedis
	 */
	public function getClient(): ?BaseRedis
	{
		return $this->_client;
	}
}
