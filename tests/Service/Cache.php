<?php
declare(strict_types=1);

namespace Tests\Service;

use Ovos\Application;
use Ovos\Cache\Store\KeyValue;
use Ovos\Cache\Store\Redis as StoreRedis;
use Ovos\Cache\Store\Redisearch;
use Ovos\Cache\Store\RedisVersioned;
use Ovos\Exception\InvalidException\InvalidConfigException;
use Ovos\Service\Cache as CacheService;
use Ovos\Service\Cache\Persistent;
use Ovos\Test;
use Ovos\Test\Exception\SkipException;
use ReflectionMethod;
use ReflectionProperty;

use function array_filter;
use function array_values;
use function in_array;

/**
 * Cache
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Cache extends Test
{
	/**
	 * RULE: a bare store name is one of the library's stores - "Redis" is
	 * Ovos\Cache\Store\Redis, never phpredis' own \Redis - and a name with a
	 * namespace separator is that class, with or without the leading backslash
	 */
	public function aStoreIsNamedBareOrByItsClass(): bool
	{
		return Persistent::storeClass('Redis') === StoreRedis::class
			&& Persistent::storeClass('RedisVersioned') === RedisVersioned::class
			&& Persistent::storeClass('\Ovos\Cache\Store\Redisearch') === Redisearch::class
			&& Persistent::storeClass('Ovos\Cache\Store\Redisearch') === Redisearch::class;
	}
	
	/**
	 * RULE: a name that is no store is refused with the configuration's own
	 * exception before anything is built - a class that does not exist, an
	 * abstract one (KeyValue), one that is no KeyValue store (phpredis' \Redis,
	 * \ArrayObject)
	 */
	public function aNameThatIsNoStoreIsRefused(): bool
	{
		foreach(['Nope', '\Nope\Missing', 'KeyValue', '\Redis', '\ArrayObject'] as $name)
		{
			try
			{
				Persistent::storeClass($name);
				
				return false;
			}
			catch(InvalidConfigException)
			{
				// refused, as it should be
			}
		}
		
		return true;
	}
	
	/**
	 * RULE: every store answers collectGarbage(), not only the Redis ones -
	 * the cache maintenance cron calls it on whatever store the persistent
	 * tier is configured to - and the default answers true, nothing to collect
	 */
	public function everyStoreCanBeAskedToCollectGarbage(): bool
	{
		$method = new ReflectionMethod(KeyValue::class, 'collectGarbage');
		
		return $method->getDeclaringClass()->getName() === KeyValue::class
			&& $method->isAbstract() === false;
	}
	
	/**
	 * RULE: a stale value's refresh (get(stale:)) waits for the response over
	 * HTTP - the service hands its store Application::afterResponse() - and
	 * runs inline in a CLI, whose shutdown a worker may not reach for hours
	 */
	public function aRefreshWaitsForTheResponseOverHttpOnly(): bool
	{
		$app = Application::$instance;
		$cache = $app?->getServices()->cache;
		if($cache instanceof CacheService === false)
		{
			throw new SkipException('the cache service is not enabled');
		}
		
		$store = $cache->getStore();
		$deferrer = $store->getDeferrer();
		$interface = $app->getInterface();
		$queue = new ReflectionProperty(Application::class, 'afterResponse');
		$ran = false;
		$refresh = function() use (&$ran): void
		{
			$ran = true;
		};
		
		try
		{
			$store->setDeferrer(null);
			$app->setInterface(Application::INT_CLI);
			$inline = $cache->getStore()->getDeferrer() === null;
			
			$app->setInterface(Application::INT_HTTP);
			$deferred = $cache->getStore()->getDeferrer();
			$deferred?->__invoke($refresh);
			$queued = in_array($refresh, $queue->getValue($app), true);
			
			return $inline
				&& $deferred !== null
				&& $queued
				&& $ran === false;
		}
		finally
		{
			$queue->setValue($app, array_values(array_filter(
				$queue->getValue($app),
				fn($callback) => $callback !== $refresh,
			)));
			$app->setInterface($interface);
			$store->setDeferrer($deferrer);
		}
	}
}
