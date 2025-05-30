<?php
declare(strict_types=1);

namespace Ovos\Service;

use Bo2Go\App;
use Ovos\Application;
use Ovos\ArrayObject;
use Ovos\Container;
use Ovos\Exception;
use Ovos\Redis\Connection;
use Ovos\Service;
use Ovos\Store\Apcu;
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
	 * @var ?Redis
	 */
	protected ?Redis $_persistentStore = null;
	
	/**
	 * @var ?Apcu
	 */
	protected ?Apcu $_perishableStore = null;
	
	/**
	 * @var array
	 */
	protected array $_dependsOn = [
		Events::SYMBOL,
	];
	
	/**
	 */
	public function __construct()
	{
		parent::__construct();
		
		$this->_config = $this->_app->getConfig()->cache;
		$this->setEnabled($this->_config->enabled);
	}
	
	/**
	 * @param Container $container
	 * @param ?string $key
	 *
	 * @return void
	 */
	public static function register(Container $container,
		?string $key = null,
	): void
	{
		$class = static::class;
		
		$config = $container->get(Application::KEY_CONFIG);
		if($config->cache->enabled === false)
		{
			$class = Disabled::class;
		}
		
		$key = $key ?? static::SYMBOL;
		$container->registerClass($key, $class);
	}
	/**
	 * @return string
	 */
	public function getSymbol(): string
	{
		return self::SYMBOL;
	}
	
	/**
	 * @return ?Connection
	 */
	public function getPersistentConnection(): ?Connection
	{
		if($this->_persistentConnection === null)
		{
			// move to container when DI is available
			$this->_persistentConnection = new Connection($this->_config->persistent);
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
			if($this->_config->offsetExists('prefix') === false)
			{
				throw new Exception('"cache: prefix" is a required config value.');
			}
			
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
