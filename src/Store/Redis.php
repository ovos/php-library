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
	 * @var string
	 */
	protected string $_hash = 'cache';
	
	/**#@+
	 * Separators
	 */
	public const SEPARATOR_PREFIX = ':';
	/**#@-*/
	
	/**#@+
	 * Keys
	 */
	public const KEY_DATA = 'data';
	/**#@-*/	
		
	/**#@+
	 * Statuses
	 * Used for rawCommand, which returns strings instead of boolean values when OPT_REPLY_LITERAL is enabled
	 * @see https://github.com/phpredis/phpredis/issues/1550
	 */
	public const STATUS_OK = 'OK';
	/**#@-*/	
	
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
	 * @param string $key
	 * @param ?string $prefix
	 *
	 * @return string
	 */
	public function prefix(string $key, ?string $prefix = null): string
	{
		return ($prefix ?: $this->_prefix) . self::SEPARATOR_PREFIX . $key;
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
	 * @return string
	 */
	public function getHashName(): string
	{
		return $this->prefix($this->_hash);
	}
	
	/**
	 * @param string $key
	 *
	 * @return null|mixed
	 */
	public function get(string $key): mixed
	{
		if(($client = $this->getClient()) === null)
		{
			return null;	
		}
		
		$value = $client->hGet(
			$this->prefix($key, $this->getHashName()),
			self::KEY_DATA,
		);
		if($value === false)
		{
			return null;
		}
	
		return $this->unserialize($this->decompress($value));
	}
	
	/**
	 * @param string $key
	 *
	 * @return null|bool
	 */
	public function delete(string $key): null|bool
	{
		if(($client = $this->getClient()) === null)
		{
			return null;	
		}
		
		return $client->unlink(
			$this->prefix($key, $this->getHashName()),
		) > 0;
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
		
		$value = $this->compress($this->serialize($value));
		
		$result = $client->hSet(
			$this->prefix($key, $this->getHashName()), 
			self::KEY_DATA, $value,
		);
		
		// set expire if needed
		if($ttl > 0)
		{
			$client->expire($key, $ttl);
		}
	
		return $result !== false;
	}
	
	/**
	 * @return bool|int
	 */
	public function clear(): bool|int
	{
		if(($client = $this->getClient()) === null)
		{
			return false;	
		}
		
		// clear all keys with our prefix
		$iterator = null;
		$count = 0;
		do
		{
			$keys = $client->scan($iterator,
				$this->prefix('*', $this->getHashName())
			);
	
			// Redis may return empty results, so protect against that
			if($keys === false)
			{
				continue;
			}
			
			foreach($keys as $key)
			{
				$keysUnlinked = $client->unlink($key);
				if($keysUnlinked !== false)
				{
					$count+= $keysUnlinked;
				}
			}
		}
		while($iterator > 0);
		
		return $count;
	}
}
