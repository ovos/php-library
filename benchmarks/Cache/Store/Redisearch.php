<?php
declare(strict_types=1);

namespace Benchmarks\Cache\Store;

use Ovos\Benchmark;
use Ovos\Cache\Store\KeyValue;
use Ovos\Cache\Store\Redisearch as Store;
use Ovos\Test\Cache\Store\TraitRedis;
use Ovos\Test\Cache\Store\TraitStoreBenchmark;

/**
 * Redisearch (RediSearch TAG index)
 *
 * Shares the scenario matrix with the other store benchmarks (see
 * TraitStoreBenchmark), so the rows are directly comparable.
 *
 * Strengths to look for: flat, cheap reads like plain Redis, plus
 * invalidation that finds its matches through the index instead of scanning
 * a tag hash. Weakness: it still deletes every matched item, so the cost
 * scales with the match size. The RediSearch query engine is bundled in
 * Redis 8, so the benchmark always runs.
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Redisearch extends Benchmark
{
	use TraitRedis;
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
		$this->store = $this->getStore(Store::class);
	}
	
	/**
	 * Drop the index on teardown, so it does not keep indexing the items the
	 * other backends write afterwards (every backend uses the same item keys)
	 */
	protected function tearDownStore(): void
	{
		$type = $this->store->getType();
		
		if($this->store->indexExists($type))
		{
			$this->store->indexDrop($type);
		}
	}
}
