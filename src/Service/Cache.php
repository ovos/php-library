<?php
declare(strict_types=1);

namespace Ovos\Service;

use Ovos\Application;
use Ovos\ArrayObject;
use Ovos\Container;
use Ovos\Exception;
use Ovos\Redis\Connection;
use Ovos\Service;
use Ovos\Store\Apcu;
use Ovos\Store\Redis;
use Ovos\Store\Redis\Cache as RedisCache;
use Ovos\Store\Redisearch;

/**
 * Cache
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
class Cache extends Service
{
	/**
	 * @var string
	 */
	public const string SYMBOL = 'cache';
	
	/**
	 * @var ArrayObject
	 */
	protected ArrayObject $_config;
	
	/**
	 * Connection to a persistent database
	 *
	 * @var ?Connection
	 */
	protected ?Connection $_persistentConnection = null;
	
	/**
	 * @var ?RedisCache
	 */
	protected ?RedisCache $_persistentStore = null;
	
	/**
	 * @var ?Apcu
	 */
	protected ?Apcu $_perishableStore = null;
	
	/**
	 * @param ArrayObject $config
	 */
	public function __construct(
		ArrayObject $config,
	)
	{
		$this->_config = $config;
	}
	
	/**
	 * @param string $key
	 * @param Container $container
	 *
	 * @return void
	 * @throws Exception
	 */
	public static function register(string $key,
		Container $container,
	): void
	{
		$class = static::class;
		
		$config = $container->get(Application::CONTAINER_KEY_CONFIG);
		if($config->cache === null)
		{
			throw new Exception('"cache" config section is missing.');
		}
		
		if($config->cache->enabled === false)
		{
			$class = Disabled::class;
		}
		
		$container->registerClass($key, $class, [
			'config' => $config->cache,
		]);
	}
	
	/**
	 * @return ?Connection
	 */
	public function getPersistentConnection(): ?Connection
	{
		if($this->_persistentConnection === null)
		{
			$this->_persistentConnection = $this->_container
				->getValue(Connection::class, new Connection($this->_config->persistent));
			if($this->_persistentConnection->connect() === false)
			{
				return null;
			}
		}
		
		return $this->_persistentConnection;
	}
	
	/**
	 * @param bool $persistent
	 * 
	 * @return null|Redis|Redisearch|Apcu
	 */
	public function getStore(bool $persistent = true): null|Redis|Redisearch|Apcu
	{
		return $persistent ?
			$this->getPersistentStore()
			: $this->getPerishableStore();
	}
	
	/**
	 * @return null|Redis|Redisearch
	 */
	public function getPersistentStore(): null|Redis|Redisearch
	{
		if($this->_persistentStore === null)
		{
			/** @var Redis $storeClass */
			$storeClass = 'Ovos\Store\\'
				. ($this->_config->persistent->store ?? 'Redis');
			$store = $storeClass::fromConfig
			(
				$this->getPersistentConnection(),
				$this->_config,
			);
			
			$this->_persistentStore = $store;
		}
		
		return $this->_persistentStore;
	}
	
	/**
	 * @return Apcu
	 */
	public function getPerishableStore(): Apcu
	{
		if($this->_perishableStore === null)
		{
			$this->_perishableStore = Apcu::fromConfig($this->_config);
		}
		
		return $this->_perishableStore;
	}
}
