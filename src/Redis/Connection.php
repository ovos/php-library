<?php
declare(strict_types=1);

namespace Ovos\Redis;

use Ovos\ArrayObject;
use Redis as BaseRedis;

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
		$timeout = (int)($this->_config->timeout ?? 1); // in seconds
		$readTimeout = (int)($this->_config->read_timeout ?? $timeout);
		
		$this->_client = new BaseRedis; // supports options since phpredis 6 (TODO in future)
		$connectionOptions = [
			BaseRedis::OPT_READ_TIMEOUT => $readTimeout,
			BaseRedis::OPT_SERIALIZER => BaseRedis::SERIALIZER_NONE,
			BaseRedis::OPT_REPLY_LITERAL => true, // https://github.com/phpredis/phpredis/issues/1550
		];
		
		// connect
		// suspend connection errors with @ since it triggers a warning when it cannot connect...
		$connectionStatus = @$this->_client->connect
		(
			$this->_config->host,
			$port,
			$timeout,
		);
		
		if($connectionStatus === false)
		{
			$this->_client = null;
			services()->events->log('Could not connect to redis server "%s"', $this->_config->host);
		}
		
		// set options
		foreach($connectionOptions as $connectionOption => $connectionOptionValue)
		{
			$this->_client->setOption($connectionOption, $connectionOptionValue);
		}
		
		$this->_client->select($this->_config->database);
		
		return $connectionStatus;
	}
	
	/**
	 * @return ?BaseRedis
	 */
	public function getClient(): ?BaseRedis
	{
		return $this->_client;
	}
}
