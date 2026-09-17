<?php
declare(strict_types=1);

namespace Benchmarks\Cache\Store;

use Ovos\Benchmark;
use Ovos\Cache\Store\KeyValue;
use Ovos\Cache\Store\RedisVersioned as Store;
use Ovos\Test\Cache\Store\TraitRedis;
use Ovos\Test\Cache\Store\TraitStoreBenchmark;

/**
 * RedisVersioned (rule-based logical invalidation)
 *
 * Shares the scenario matrix with the other store benchmarks (see
 * TraitStoreBenchmark), so the rows are directly comparable.
 *
 * Strengths to look for: invalidation is O(1) - invalidateMatchingAll,
 * invalidateMatchingPartial and invalidateRepeated all stay flat regardless
 * of how many items match. Weaknesses: every read evaluates the rules the
 * item has not seen, so readHitsAfterSmallBacklog / readHitsAfterLargeBacklog
 * climb as the rule backlog grows - the cost the O(1) invalidation defers.
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class RedisVersioned extends Benchmark
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
	
	public const int FRESH_INSTANCE_EVERY = 10;
	
	protected ?Store $store = null;
	
	public function __construct()
	{
		$this->group = KeyValue::GROUP_BENCHMARKS;
		$this->store = $this->getStore(Store::class);
	}
	
	/**
	 * clear() on the versioned stores is logical (it appends a rule), so the
	 * items would linger until their TTL; wipe them physically between methods
	 */
	protected function resetStore(): void
	{
		$this->store->clearPhysical();
	}
	
	/**
	 * The FPM model with the shared rules cache off: what a cold instance
	 * pays when nothing survives the request - the rules of the whole
	 * retention window, loaded per "request". Compare with
	 * readHitsFreshInstanceAfterLargeBacklog, where the instances share them
	 */
	public function readHitsFreshInstanceAfterLargeBacklogNoSharedRules(): void
	{
		$this->freshStoreOptions = ['rules_shared_cache' => false];
		
		try
		{
			$this->readHitsFreshInstanceAfterLargeBacklog();
		}
		finally
		{
			$this->freshStoreOptions = [];
		}
	}
}
