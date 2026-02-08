<?php
declare(strict_types=1);

namespace Ovos\Service;

use Ovos\Application;
use Ovos\ArrayObject;
use Ovos\Cache\MemoLock\Apcu as MemoLockApcu;
use Ovos\Cache\MemoLock\Redis as MemoLockRedis;
use Ovos\Cache\Store\Apcu as StoreApcu;
use Ovos\Cache\Store\Redis as StoreRedis;
use Ovos\Cache\Store\Redisearch as StoreRedisearch;
use Ovos\Container;
use Ovos\Exception\MissingException\MissingConfigException;
use Ovos\Service;
use Ovos\Service\Cache\Perishable;
use Ovos\Service\Cache\Persistent;

/**
 * Cache
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Cache extends Service
{
	public const string SYMBOL = 'cache';
	
	protected ArrayObject $config;
	
	protected ?Persistent $persistent = null;
	protected ?Perishable $perishable = null;
	
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
	
	public function getPersistent(): Persistent
	{
		if($this->persistent === null)
		{
			$this->persistent = $this->container
				->getClass(Persistent::class, parameters: [
					'config' => $this->config,
				]);
		}
		
		return $this->persistent;
	}
	
	public function getPerishable(): Perishable
	{
		if($this->perishable === null)
		{
			$this->perishable = $this->container
				->getClass(Perishable::class, parameters: [
					'config' => $this->config,
				]);
		}
		
		return $this->perishable;
	}
	
	public function getStore(
		bool $persistent = true,
	): StoreApcu|StoreRedis|StoreRedisearch
	{
		return $persistent
			? $this->getPersistent()->getStore()
			: $this->getPerishable()->getStore();
	}
	
	public function getQueue(
		bool $persistent = true,
	): MemoLockApcu|MemoLockRedis
	{
		return $persistent
			? $this->getPersistent()->getQueue()
			: $this->getPerishable()->getQueue();
	}
}
