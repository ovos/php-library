<?php
declare(strict_types=1);

namespace Ovos\Test\Cache\Store;

use Ovos\ArrayObject;
use Ovos\Cache\MemoLock\Redis as RedisMemoLock;
use Ovos\Cache\Store\Redis;
use Ovos\Cache\Store\Redisearch;
use Ovos\Container\ArrayObject as InjectArrayObject;
use Ovos\Container\Inject;
use Ovos\Cache\Store\KeyValue;
use Ovos\Service\Cache\Persistent;

/**
 * TraitRedis
 *
 * @author Marcin Gil <mg@ovos.at>
 */
trait TraitRedis
{
	#[Inject('config')]
	#[InjectArrayObject('cache')]
	protected ArrayObject $cacheConfig;
	
	protected string $group = KeyValue::GROUP_TESTS;
	
	protected function getStore(
		string $storeClass,
	): Redis|Redisearch
	{
		$persistent = $this->container
			->getClass(Persistent::class, parameters: [
				'config' => $this->cacheConfig,
			]);
		
		/** @var Redis|Redisearch $storeClass */
		return new $storeClass(
			$persistent->getConnection(),
			$persistent->getQueueConnection(),
			$this->cacheConfig->prefix,
			$this->cacheConfig->persistent,
			$this->group,
		);
	}
	
	protected function getMemoLock(): RedisMemoLock
	{
		$persistent = $this->container
			->getClass(Persistent::class, parameters: [
				'config' => $this->cacheConfig,
			]);
		
		return new RedisMemoLock(
			$persistent->getConnection(),
			$persistent->getQueueConnection(),
			$this->cacheConfig->prefix,
			$this->cacheConfig->persistent,
			$this,
		);
	}
}

