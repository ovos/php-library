<?php
declare(strict_types=1);

namespace Ovos\Store;

use Ovos\Exception;
use Ovos\ArrayObject;
use Ovos\Redis\Connection;
use Redis as BaseRedis;

/**
 * Redis
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
class Redis extends Cache
{
	/**
	 * Compress prefix
	 */
	public const COMPRESS_PREFIX = ":\x1f\x8b";
	public const SERIALIZE_PREFIX = "\x01\xe4";

	/**
	 * Redis connection
	 *
	 * @var ?Connection
	 */
	protected ?Connection $_connection = null;
	
	/**
	 * @var ?string 
	 */
	protected ?string $_prefix = null;
	
	/**
	 * @param ArrayObject $config
	 */
	public function __construct(ArrayObject $config)
	{
		parent::__construct();
	
		if($config->offsetExists('prefix') === false)
		{
			throw new Exception('"cache: prefix" is a required config value.');
		}
		
		$this->setPrefix($config->prefix);
		$this->setConfig($config->persistent);
	}
	
	/**
	 * @param ?string $prefix
	 *
	 * @return self
	 */
	public function setPrefix(?string $prefix = null): self
	{
		$this->_prefix = $prefix;
		
		return $this;
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
	 * @return ?BaseRedis
	 */
	public function getClient(): ?BaseRedis
	{
		return $this->_connection->getClient();
	}
	
	/**
	 * @param string $key
	 *
	 * @return false|string
	 */
	public function get(string $key): false|string
	{
		if(($client = $this->getClient()) === null)
		{
			return false;			
		}
	
		$key = $this->_prefix . $key;
	
		$value = $client->get($key);
		if($value === false)
		{
			return false;
		}
	
		return $this->unserialize($this->decompress($value));
	}
	
	/**
	 * @param string $key
	 * @param mixed $value
	 * @param int $ttl
	 *
	 * @return bool
	 */
	public function set(string $key, mixed $value, int $ttl = 0): bool
	{
		if(($client = $this->getClient()) === null)
		{
			return false;			
		}
	
		$key = $this->_prefix . $key;	
	
		$value = $this->compress($this->serialize($value));
	
		$result = $client->set($key, $value);
		
		// set expire if needed
		if($ttl > 0)
		{
			$client->expire($key, $ttl);
		}
	
		return $result;
	}
}
