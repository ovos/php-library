<?php
declare(strict_types=1);

namespace Ovos\Service;

use Ovos\ArrayObject;
use Ovos\Service;
use Cache\Adapter\Common\AbstractCachePool;
use Cache\Prefixed\PrefixedCachePool;
use Cache\Adapter\Apcu\ApcuCachePool;
use Cache\Adapter\Filesystem\FilesystemCachePool;
use Ovos\Cache\Adapter\Redis\RedisCachePool;
use function Ovos\services;

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
	public const SYMBOL = 'cache';

	/**
	 * @var ArrayObject
	 */
	protected $_config;

	/**
	 * @var RedisCachePool
	 */
	protected $_persistentPool;

	/**
	 * @var ApcuCachePool
	 */
	protected $_perishablePool;

	/**
	 * @var array
	 */
	protected $_dependsOn = [Events::SYMBOL];

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
	 * @return RedisCachePool|PrefixedCachePool
	 */
	public function getPool($persistent = true)
	{
		return $persistent ?
			$this->getPersistentPool()
			: $this->getPerishablePool();
	}

	/**
	 * @return RedisCachePool
	 */
	public function getPersistentPool(): RedisCachePool
	{
		if($this->_persistentPool === null)
		{
			$client = new \Ovos\Cache\Redis($this->_config->persistent);
			if($client->connect() === false)
			{
				return null;
			}

			$this->_persistentPool = $client->getCachePool();
		}

		return $this->_persistentPool;
	}
	
	/**
	 * @return PrefixedCachePool
	 */
	public function getPerishablePool(): PrefixedCachePool
	{
		if($this->_perishablePool === null)
		{
			$this->_perishablePool = new PrefixedCachePool(
				new ApcuCachePool, $this->_config->perishable->prefix);
		}

		return $this->_perishablePool;
	}
}
