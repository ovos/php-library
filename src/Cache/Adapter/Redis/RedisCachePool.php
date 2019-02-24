<?php
declare(strict_types=1);

namespace Ovos\Cache\Adapter\Redis;

use Cache\Adapter\Redis\RedisCachePool as BaseRedisCachePool;
use Cache\Adapter\Common\PhpCacheItem;
use Ovos\Cache\Adapter\Common\CacheItem;
use Ovos\ArrayObject;
use Ovos\Service\Cache;
use Redis;
use ReflectionObject;

class RedisCachePool extends BaseRedisCachePool
{
	/**
	 * @var ArrayObject
	 */
	protected $_config;

	/**
	 * @param Redis $cache
	 * @param ArrayObject $config
	 */
	public function __construct(Redis $cache, ArrayObject $config)
	{
		parent::__construct($cache);
		$this->setConfig($config);
	}
	
	/**
	 * @param ArrayObject $config
	 * 
	 * @return $this
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
	 * {@inheritdoc}
	 */
	protected function storeItemInCache(PhpCacheItem $item, $ttl): bool
	{
		/** @var $item CacheItem */
		$item->setRaw(true);
		$result = parent::storeItemInCache($item, $ttl);
		$item->setRaw(false);
		
		return $result;
	}

	/**
	 * {@inheritdoc}
	 */
	public function getItem($key)
	{
		$item = parent::getItem($key);
		$reflected = new ReflectionObject($item);
		
		$key = $reflected->getProperty('key');
		$key->setAccessible(true);
		
		$callable = $reflected->getProperty('callable');
		$callable->setAccessible(true);
		
		// use our own class
		return new CacheItem($this->_config,
			$key->getValue($item),
			$callable->getValue($item));
	}
}

