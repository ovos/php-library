<?php
declare(strict_types=1);

namespace Ovos\Service;

use Ovos\Application;
use Ovos\ArrayObject;
use Ovos\Container;
use Ovos\Connections;
use Ovos\Connection\Redis as Connection;
use Ovos\Exception\MissingException\MissingConfigException;
use Ovos\Service;
use Ovos\Store\Apcu;
use Ovos\Store\KeyValue\Redis as RedisStore;
use Ovos\Store\Redis;
use Ovos\Store\Redisearch;

/**
 * Cache
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
class Cache extends Service
{
	public const string SYMBOL = 'cache';
	
	protected ArrayObject $config;
	
	/**
	 * Connection to a persistent database
	 */
	protected ?Connection $persistentConnection = null;
	
	protected ?RedisStore $persistentStore = null;
	
	protected ?Apcu $perishableStore = null;
	
	public function __construct(
		ArrayObject $config,
	)
	{
		$this->config = $config;
	}
	
	public static function register(
		string $key,
		Container $container,
	): void
	{
		$class = static::class;
		
		$config = $container->get(Application::CONTAINER_KEY_CONFIG);
		if($config->cache === null)
		{
			throw new MissingConfigException('"cache" config section is missing.');
		}
		
		if($config->cache->enabled === false)
		{
			$class = Disabled::class;
		}
		
		$container->registerClass($key, $class, [
			'config' => $config->cache,
		]);
	}
	
	public function getPersistentConnection(): ?Connection
	{
		if($this->persistentConnection === null)
		{
			if($this->config->persistent->connection === null)
			{
				throw new MissingConfigException('"connection" config section is missing.');
			}
			
			$this->persistentConnection = $this->container
				->getClass(Connections::class)
				->get($this->config->persistent->connection);
		}
		
		return $this->persistentConnection;
	}
	
	public function getStore(
		bool $persistent = true,
	): null|Redis|Redisearch|Apcu
	{
		return $persistent ?
			$this->getPersistentStore()
			: $this->getPerishableStore();
	}
	
	public function getPersistentStore(
	): null|Redis|Redisearch
	{
		if($this->persistentStore === null)
		{
			/** @var Redis $storeClass */
			$storeClass = 'Ovos\Store\\'
				. ($this->config->persistent->store ?? 'Redis');
			$store = $storeClass::fromConfig
			(
				$this->getPersistentConnection(),
				$this->config,
			);
			
			$this->persistentStore = $store;
		}
		
		return $this->persistentStore;
	}
	
	public function getPerishableStore(): Apcu
	{
		if($this->perishableStore === null)
		{
			$this->perishableStore = Apcu::fromConfig($this->config);
		}
		
		return $this->perishableStore;
	}
}
