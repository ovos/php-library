<?php
declare(strict_types=1);

namespace Benchmarks\Cache\Store;

use Ovos\Benchmark;
use Ovos\Cache\Store\KeyValue;
use Ovos\Cache\Store\RedisClusterVersioned as Store;
use Ovos\Test\Cache\Store\TraitRedisCluster;
use Ovos\Test\Cache\Store\TraitStoreBenchmark;

/**
 * RedisClusterVersioned (versioned model across cluster slots)
 *
 * Shares the scenario matrix with the other store benchmarks (see
 * TraitStoreBenchmark), so the rows are directly comparable.
 *
 * Same logical model as RedisVersioned: O(1) invalidation, with the read
 * cost growing as the rule backlog grows. The difference is on the read
 * path - the rules are evaluated in PHP behind a short-lived local cache
 * (rules_cache_ms) rather than server side - and writes pay one extra round
 * trip to read the watermark. Watch readHitsAfterLargeBacklog and
 * writeOverwrite against RedisVersioned to see those two effects.
 *
 * Skipped unless a "redis_cluster" (and "redis_cluster_queue") connection
 * is configured and reachable.
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class RedisClusterVersioned extends Benchmark
{
	use TraitRedisCluster;
	use TraitStoreBenchmark;
	
	public const int ITEMS = 10000;
	
	public const int TAGS_PER_ITEM = 20;
	
	public const int GROUPS = 10;
	
	public const int BACKLOG_SMALL = 100;
	
	public const int BACKLOG_LARGE = 1000;
	
	public const int CHURN_ROUNDS = 50;
	
	public const int CHURN_BATCH = 2000;
	
	public const int CHURN_HOT = 100;
	
	protected ?Store $store = null;
	
	public function __construct()
	{
		$this->group = KeyValue::GROUP_BENCHMARKS;
		$this->store = $this->getClusterStore();
		
		if($this->store === null)
		{
			$this->setDisabled(true, $this->clusterUnavailableReason);
		}
	}
	
	/**
	 * clear() on the versioned stores is logical (it appends a rule), so the
	 * items would linger until their TTL; wipe them physically between methods
	 * (cache_clear runs once per master node on a cluster)
	 */
	protected function resetStore(): void
	{
		$this->store?->clearPhysical();
	}
}
