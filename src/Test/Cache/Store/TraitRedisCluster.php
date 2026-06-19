<?php
declare(strict_types=1);

namespace Ovos\Test\Cache\Store;

use Ovos\Cache\Store\RedisClusterVersioned;
use Ovos\Connections;
use Ovos\Exception\MissingException\MissingConfigException;

/**
 * TraitRedisCluster
 *
 * Builds the cluster store for tests and benchmarks. Two connections
 * are required in the environment config:
 * - "redis_cluster": the cluster itself
 * - "redis_cluster_queue": a standalone connection to one of the
 *   cluster nodes - pub/sub (MemoLock queueing) needs a plain client,
 *   and the subscriber must be attached to the same cluster the lock
 *   release publishes on (a classic PUBLISH is broadcast cluster-wide,
 *   but never to a different Redis instance)
 *
 * @author Marcin Gil <mg@ovos.at>
 */
trait TraitRedisCluster
{
	use TraitRedis;
	
	/**
	 * Why the store is unavailable (used as the skip reason)
	 */
	protected ?string $clusterUnavailableReason = null;
	
	protected function getClusterStore(): ?RedisClusterVersioned
	{
		$connections = $this->container
			->getClass(Connections::class);
		
		foreach(['redis_cluster', 'redis_cluster_queue'] as $name)
		{
			try
			{
				$connections->getConnectionConfig($name);
			}
			catch(MissingConfigException)
			{
				$this->clusterUnavailableReason =
					'no "' . $name . '" connection configured';
				
				return null;
			}
		}
		
		$connection = $connections->get('redis_cluster');
		
		if($connection->getClient() === null)
		{
			$this->clusterUnavailableReason =
				'the redis cluster is not reachable';
			
			return null;
		}
		
		return new RedisClusterVersioned(
			$connection,
			$connections->get('redis_cluster_queue', 'queue'),
			$this->cacheConfig->prefix,
			$this->cacheConfig->persistent,
			$this->group,
		);
	}
}
