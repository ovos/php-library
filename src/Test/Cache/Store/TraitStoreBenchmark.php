<?php
declare(strict_types=1);

namespace Ovos\Test\Cache\Store;

use Ovos\Test\Internal;

/**
 * TraitStoreBenchmark
 *
 * The scenarios shared by every cache store benchmark, so the rows are
 * directly comparable across backends. Each public method is timed once
 * by the runner (wall time + memory); the loop sizes are driven by the
 * tunable constants the using class declares, never hard-coded in a name:
 *
 * - ITEMS           how many items are filled / read
 * - TAGS_PER_ITEM   tags carried by a single item (the per-item tag width)
 * - GROUPS          number of partial-match groups (partial set ~ ITEMS / GROUPS)
 * - BACKLOG_SMALL   invalidations applied before the "small backlog" read
 * - BACKLOG_LARGE   invalidations applied before the "large backlog" read
 * - CHURN_ROUNDS    clear/read/rebuild cycles in the churn scenario
 * - CHURN_BATCH     items in the churn working set (the invalidation fan-out)
 * - CHURN_HOT       hot items re-read each churn round (the rest stay cold)
 *
 * The using class provides the store as $this->store and may override
 * resetStore() (clear vs clearPhysical) and tearDownStore() (e.g. dropping
 * a RediSearch index) for backend-specific teardown.
 *
 * Reading the results:
 * - readHits* show the read cost. The eager stores (Redis, Redisearch)
 *   stay flat as the backlog grows; the versioned stores pay more per read
 *   the longer the un-trimmed rule backlog gets - this is the cost the O(1)
 *   invalidation defers to the read path.
 * - invalidateMatching* show the invalidation cost. The eager stores scale
 *   with the number of matched items; the versioned stores are flat (one
 *   appended rule) regardless of the match size.
 * - churn is the real-life mixed workload: wide invalidations but only a hot
 *   subset re-read. On loopback reads dominate, so the stores land close; the
 *   invalidation cost gap itself shows up isolated in invalidateMatching*.
 *
 * @author Marcin Gil <mg@ovos.at>
 */
trait TraitStoreBenchmark
{
	/**
	 * A tag carried by every item: invalidating it matches the whole set
	 * (the 100% match case). Not meant to be tuned.
	 */
	protected const string TAG_GLOBAL = 'all';
	
	/**
	 * Builds a list of $count tags ("$prefix1", "$prefix2", ...);
	 * a $count below 1 yields an empty list
	 */
	protected function buildTags(
		int $count,
		string $prefix = 'tag',
	): array
	{
		$tags = [];
		for($i = 1; $i <= $count; $i++)
		{
			$tags[] = $prefix . $i;
		}
		
		return $tags;
	}
	
	/**
	 * Fills static::ITEMS items. Every item carries:
	 * - TAG_GLOBAL (the 100% match set, used by invalidateMatchingAll)
	 * - one group tag "group{i mod GROUPS}" (~ITEMS / GROUPS items per group,
	 *   the partial match set used by invalidateMatchingPartial)
	 * - filler tags up to static::TAGS_PER_ITEM (the per-item tag width)
	 */
	protected function fill(): void
	{
		// two tags are reserved (global + group), the rest are filler
		$filler = $this->buildTags(static::TAGS_PER_ITEM - 2, 'filler');
		
		for($i = 1; $i <= static::ITEMS; $i++)
		{
			$tags = $filler;
			$tags[] = static::TAG_GLOBAL;
			$tags[] = 'group' . ($i % static::GROUPS);
			
			$this->store->set('item' . $i, 'test', tags: $tags);
		}
	}
	
	/**
	 * Reads every item once as a plain cache hit (no resolver, no re-fill)
	 */
	protected function readAll(): void
	{
		for($i = 1; $i <= static::ITEMS; $i++)
		{
			$this->store->get('item' . $i);
		}
	}
	
	/**
	 * Appends $count invalidations on tags that NO item carries, so the
	 * items survive in every backend: the eager stores match nothing (reads
	 * stay hits and stay flat), the versioned stores accumulate rules every
	 * later read must evaluate. This isolates the read-side cost of an
	 * invalidation backlog from the cost of re-resolving deleted items.
	 */
	protected function invalidateUnrelated(
		int $count,
	): void
	{
		for($i = 1; $i <= $count; $i++)
		{
			$this->store->invalidateTags(['unrelated' . $i]);
		}
	}
	
	/**
	 * Baseline read latency: every item read once, no invalidation backlog
	 */
	public function readHits(): void
	{
		$this->readAll();
	}
	
	/**
	 * Read latency after a small backlog of (unrelated) invalidations
	 */
	public function readHitsAfterSmallBacklog(): void
	{
		$this->invalidateUnrelated(static::BACKLOG_SMALL);
		$this->readAll();
	}
	
	/**
	 * Read latency after a large backlog of (unrelated) invalidations -
	 * the row where a growing rule backlog shows up on the versioned stores
	 */
	public function readHitsAfterLargeBacklog(): void
	{
		$this->invalidateUnrelated(static::BACKLOG_LARGE);
		$this->readAll();
	}
	
	/**
	 * One invalidation matching every item (the global tag)
	 */
	public function invalidateMatchingAll(): void
	{
		$this->store->invalidateTags([static::TAG_GLOBAL]);
	}
	
	/**
	 * One invalidation matching a fraction (~ITEMS / GROUPS) of the items
	 */
	public function invalidateMatchingPartial(): void
	{
		$this->store->invalidateTags(['group0']);
	}
	
	/**
	 * Many invalidation calls in a row: shows whether per-call cost holds up
	 * and (for the versioned stores) grows the rules stream - pair the result
	 * with readHitsAfterLargeBacklog to see the read-side consequence
	 */
	public function invalidateRepeated(): void
	{
		$this->invalidateUnrelated(static::BACKLOG_LARGE);
	}
	
	/**
	 * Overwrites every item: exercises the full write path (serialize,
	 * compress, store, tag bookkeeping) under each backend
	 */
	public function writeOverwrite(): void
	{
		$this->fill();
	}
	
	/**
	 * A realistic mixed workload aimed at the versioned model's target case:
	 * invalidations fan out widely, but only a hot subset is re-read. Each
	 * round clears one random tag (a large slice of the CHURN_BATCH set, the
	 * way a "a whole section changed" invalidation lands), then reads just the
	 * CHURN_HOT hot items and rebuilds their misses; the rest of the cleared
	 * slice is left to resolve lazily or expire - as in production, where most
	 * invalidated entries are never re-read before their TTL.
	 *
	 * The eager stores delete the whole slice up front (most of it wasted work);
	 * the versioned stores append one rule and let only the hot reads pay. On
	 * loopback the reads still dominate, so wall time lands close - the gap is
	 * the invalidate column. The PRNG is seeded so the tags repeat run to run.
	 */
	public function churn(): void
	{
		$filler = $this->buildTags(static::TAGS_PER_ITEM - 1, 'filler');
		
		// seed a large working set: one of GROUPS tags per item (so one tag is
		// a wide slice ~ CHURN_BATCH / GROUPS), plus filler for a tag width
		for($j = 1; $j <= static::CHURN_BATCH; $j++)
		{
			$tags = $filler;
			$tags[] = 'churntag' . ($j % static::GROUPS);
			
			$this->store->set('churn' . $j, 'test', tags: $tags);
		}
		
		// deterministic PRNG: the random tags below are reproducible run to run
		mt_srand(static::CHURN_BATCH + static::CHURN_ROUNDS);
		
		for($r = 1; $r <= static::CHURN_ROUNDS; $r++)
		{
			// clear a random tag (a wide slice), then re-read only the hot
			// subset and rebuild its misses; the cold items are left behind
			$this->store->invalidateTags(['churntag' . mt_rand(0, static::GROUPS - 1)]);
			
			for($j = 1; $j <= static::CHURN_HOT; $j++)
			{
				if($this->store->get('churn' . $j) !== null)
				{
					continue;
				}
				
				$tags = $filler;
				$tags[] = 'churntag' . ($j % static::GROUPS);
				
				$this->store->set('churn' . $j, 'test', tags: $tags);
			}
		}
	}
	
	/**
	 * Called by the runner before each measured method.
	 *
	 * Clears the store BEFORE filling, not only after: every backend writes
	 * the same item keys in the same group, so a method must never inherit
	 * items, a rule backlog or a RediSearch index left by another backend or
	 * an interrupted run. The reset runs before the clock, so it is untimed.
	 */
	#[Internal]
	public function prepare(): void
	{
		$this->resetStore();
		$this->fill();
	}
	
	/**
	 * Empties the store. The eager stores clear physically; the versioned
	 * stores override this with clearPhysical() (their clear() is logical)
	 */
	protected function resetStore(): void
	{
		$this->store->clear();
	}
	
	/**
	 * Called by the runner after all test methods have been invoked: leave
	 * the shared group clean for the next backend, run any backend-specific
	 * teardown (e.g. dropping the RediSearch index), then disconnect
	 */
	#[Internal]
	public function deconstruct(): void
	{
		$this->resetStore();
		$this->tearDownStore();
		$this->store?->getConnection()
			->disconnect();
	}
	
	/**
	 * Backend-specific final teardown (overridable); e.g. Redisearch drops
	 * its index so it stops indexing the items the other backends write
	 */
	protected function tearDownStore(): void
	{
	}
}
