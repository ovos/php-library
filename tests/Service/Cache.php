<?php
declare(strict_types=1);

namespace Tests\Service;

use Ovos\Application;
use Ovos\Service\Cache as CacheService;
use Ovos\Test;
use Ovos\Test\Exception\SkipException;
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
