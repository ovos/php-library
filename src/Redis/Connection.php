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
	
	/**#@+
	 * Timeout constants
	 */
	public const string TIMEOUT_READ = 'read';
	public const string TIMEOUT_READ_LONG = 'long';
	/**#@-*/
	
	/**
	 * Connect timeout
	 * Unit: seconds
	 * 
	 * @var float
	 */
	protected float $_connectTimeout = 1;
	
	/**
	 * Read timeout for light operations
	 * Unit: seconds
	 *  
	 * @var float
	 */
	protected float $_readTimeout = 1;
	
	/**
	 * Read timeout for heavy operations
	 * Unit: seconds
	 * 
	 * @var float
	 */
	protected float $_readTimeoutLong = 10;
	
	/**
	 * @param ArrayObject $config
	 */
	public function __construct(ArrayObject $config)
	{
		$this->setConfig($config);
		
		// initialize timeout values taking in consideration default values set in this class
		$this->_connectTimeout = (float)
		(
			$this->_config->connect_timeout
			?? $this->_config->timeout
			?? $this->_connectTimeout
		);
		
		$this->_readTimeout = (float)
		(
			$this->_config->read_timeout
			?? $this->_readTimeout
		);
		
		$this->_readTimeoutLong = (float)
		(
			$this->_config->read_timeout_long
			?? $this->_readTimeoutLong
		);
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
	 * Can be used to extend and restore timeout to the original value
	 * 
	 * @param string $timeout
	 *
	 * @return bool
	 */
	public function toggleReadTimeout(string $timeout = self::TIMEOUT_READ): bool
	{
		if(($client = $this->getClient()) === null)
		{
			return false;
		}
		
		$readTimeout = match($timeout)
		{
			self::TIMEOUT_READ_LONG => $this->_readTimeoutLong,
			default => $this->_readTimeout,
		};
		
		$client->setOption(BaseRedis::OPT_READ_TIMEOUT, $readTimeout);
		$client->config('SET', 
			'lua-time-limit',
			(string)($readTimeout * 1000) // ms
		);
		
		return true;
	}
	
	/**
	 * @return bool
	 */
	public function connect(): bool
	{
		$port = (int)($this->_config->port ?? 6379);
		
		$connectionOptions = [
			'host' => $this->_config->host,
			'port' => $port,
			'connectTimeout' => $this->_connectTimeout,
		];
		$this->_client = new BaseRedis($connectionOptions);
		
		$options = [
			BaseRedis::OPT_READ_TIMEOUT => $this->_readTimeout,
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
