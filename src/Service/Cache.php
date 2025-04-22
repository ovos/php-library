<?php
declare(strict_types=1);

namespace Ovos\Service;

use Ovos\ArrayObject;
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
	 * @return string
	 */
	public function getSymbol(): string
	{
		return self::SYMBOL;
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
			$storeClass = $this->_config->persistent->store;
			$store = $storeClass
				? new ('Ovos\Store\\' . $storeClass)($this->_config)
				: new Redis($this->_config);
			if($store->connect() === false)
			{
				return null;
			}
			
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
