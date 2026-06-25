<?php
declare(strict_types=1);

namespace Benchmarks\Cache\Store;

use Ovos\Benchmark;
use Ovos\Cache\Store\KeyValue;
use Ovos\Cache\Store\Redis as Store;
use Ovos\Test\Cache\Store\TraitRedis;
use Ovos\Test\Cache\Store\TraitStoreBenchmark;

/**
 * Redis (legacy tag-hash model)
 *
 * Shares the scenario matrix with the other store benchmarks (see
 * TraitStoreBenchmark) and adds the two tag-hash specifics: the clean_tags
 * option and a garbage collector run.
 *
 * Strengths to look for: flat, cheap reads (a single HGET) that do not
 * degrade as the invalidation backlog grows. Weaknesses: invalidation cost
 * scales with the number of matched items, and with clean_tags off it leaves
 * dangling references the garbage collector has to reclaim.
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Redis extends Benchmark
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
	 * Invalidate everything with clean_tags OFF: fast (no tag cleanup),
	 * but it leaves dangling tag -> id references for the garbage collector
	 */
	public function invalidateCleanTagsOff(): void
	{
		$this->store->setCleanTags(false);
		$this->store->invalidateTags([static::TAG_GLOBAL]);
	}
	
	/**
	 * Invalidate everything with clean_tags ON: slower (every matched id is
	 * also removed from its tags), but the tag index stays compact
	 */
	public function invalidateCleanTagsOn(): void
	{
		$this->store->setCleanTags(true);
		$this->store->invalidateTags([static::TAG_GLOBAL]);
	}
	
	/**
	 * A garbage collector run on a dirtied store: invalidate every group with
	 * clean_tags off (the items are unlinked, but their ids stay behind in
	 * TAG_GLOBAL and the filler tags), then reclaim the dangling references.
	 * The timing therefore covers the dirtying invalidations plus the sweep.
	 */
	public function garbageCollect(): void
	{
		$this->store->setCleanTags(false);
		
		for($g = 0; $g < static::GROUPS; $g++)
		{
			$this->store->invalidateTags(['group' . $g]);
		}
		
		$this->store->collectGarbage();
	}
	
	/**
	 * Reset clean_tags to the default before clearing, so a clean_tags test
	 * cannot leak its flag into the next method (the store instance is reused)
	 */
	protected function resetStore(): void
	{
		$this->store->setCleanTags(false);
		$this->store->clear();
	}
}
