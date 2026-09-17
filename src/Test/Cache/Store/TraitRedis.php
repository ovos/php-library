<?php
declare(strict_types=1);

namespace Ovos\Test\Cache\Store;

use Ovos\ArrayObject;
use Ovos\Cache\MemoLock\Redis as RedisMemoLock;
use Ovos\Cache\Store\KeyValue\Redis as KeyValueRedis;
use Ovos\Container\ArrayObject as InjectArrayObject;
use Ovos\Container\Inject;
use Ovos\Cache\Store\KeyValue;
use Ovos\Service\Cache\Persistent;

use function array_merge;
use function count;
use function json_decode;
use function json_encode;

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
	
	/**
	 * Builds a store on the persistent tier's connections; $storeOptions
	 * override single "store_options" entries of the configured tier (see
	 * storeConfig())
	 */
	protected function getStore(
		string $storeClass,
		array $storeOptions = [],
	): KeyValueRedis
	{
		$persistent = $this->container
			->getClass(Persistent::class, parameters: [
				'config' => $this->cacheConfig,
			]);
		
		/** @var KeyValueRedis $storeClass */
		return new $storeClass(
			$persistent->getConnection(),
			$persistent->getQueueConnection(),
			$this->cacheConfig->prefix,
			$this->storeConfig($storeOptions),
			$this->group,
		);
	}
	
	/**
	 * The persistent tier's config, with $storeOptions merged into its
	 * "store_options" - on a deep copy, so the shared config is left as it is
	 */
	protected function storeConfig(
		array $storeOptions = [],
	): ArrayObject
	{
		if(count($storeOptions) === 0)
		{
			return $this->cacheConfig->persistent;
		}
		
		$config = new ArrayObject(
			json_decode(json_encode($this->cacheConfig->persistent), true),
		);
		$config->store_options = array_merge(
			$config->getArray('store_options'),
			$storeOptions,
		);
		
		return $config;
	}
	
	/**
	 * @param class-string<RedisMemoLock> $class a subclass, when a test needs one
	 */
	protected function getMemoLock(
		string $class = RedisMemoLock::class,
	): RedisMemoLock
	{
		$persistent = $this->container
			->getClass(Persistent::class, parameters: [
				'config' => $this->cacheConfig,
			]);
		
		return new $class(
			$persistent->getConnection(),
			$persistent->getQueueConnection(),
			$this->cacheConfig->prefix,
			$this->cacheConfig->persistent,
			$this,
		);
	}
}
