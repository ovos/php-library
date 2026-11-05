<?php
declare(strict_types=1);

namespace Tests\Cache\Store;

use Ovos\Cache\Redis\Functions;
use Ovos\Cache\Stale;
use Ovos\Cache\Store\Redisearch as Store;
use Ovos\Cache\Store\KeyValue\Redis as KeyValueRedis;
use Ovos\Cache\Store\KeyValue\TagIndex;
use Ovos\Test;
use Ovos\Test\Internal;
use Ovos\Test\Cache\Store\TraitBatch;
use Ovos\Test\Cache\Store\TraitInvalidationGuard;
use Ovos\Test\Cache\Store\TraitStaleWhileRevalidate;
use Ovos\Test\Cache\Store\TraitCachePolicy;
use Ovos\Test\Cache\Store\TraitStoredStrings;
use Ovos\Test\Cache\Store\TraitRedis;
use Ovos\Test\Cache\Store\TraitTagIndexMiss;
use Ovos\Test\Cache\Store\TagIndexCounting;
use Override;

use function array_map;
use function implode;
use function is_array;
use function preg_match;
use function str_contains;

/**
 * Redisearch
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Redisearch extends Test
{
	use TraitRedis;
	use TraitInvalidationGuard;
	use TraitStaleWhileRevalidate;
	use TraitStoredStrings;
	use TraitCachePolicy;
	use TraitTagIndexMiss;
	use TraitBatch;
	
	public const string KEY_ITEM = 'item';
	
	protected ?Store $store = null;
	
	public function __construct()
	{
		$this->store = $this->getStore(Store::class);
		
		// RediSearch only indexes database 0; skip when the cache connection
		// is configured for another database (the index cannot live there)
		if($this->store->getConnection()->getConfig()->database !== 0)
		{
			$this->setDisabled(true,
				'RediSearch requires database 0; cache connection is not on db0.');
			
			return;
		}
	}
	
	public function delete(): bool
	{
		$this->store->set(self::KEY_ITEM, 'test');
		$this->store->delete(self::KEY_ITEM);
		
		$exists = $this->store->get(self::KEY_ITEM, queue: false);
		
		return $exists === null;
	}
	
	public function storeArray(): bool
	{
		$this->store->indexRebuild();
		
		$array = [
			'stored' => true
		];
		
		$this->store->set(self::KEY_ITEM, $array);
		$array = $this->store->get(self::KEY_ITEM, queue: false);
		
		try
		{
			return $array['stored'] === true;
		}
		finally
		{
			$this->store->delete(self::KEY_ITEM);
		}
	}
	
	public function invalidateTags(): bool
	{
		$this->store->indexRebuild();
		
		$tags = ['tag1', 'tag2'];
		
		$this->store->set(self::KEY_ITEM, 'test', tags: $tags);
		$this->store->invalidateTags([$tags[0]]);
		
		$result = $this->store->get(self::KEY_ITEM, queue: false);
		
		try
		{
			return $result === null;
		}
		finally
		{
			$this->store->delete(self::KEY_ITEM);
		}
	}
	
	/**
	 * RULE: a tag with punctuation or a space is invalidated like any other -
	 * unescaped, "user:42" made the whole FT.SEARCH a syntax error, and every
	 * item it should have reached stayed
	 */
	public function aTagWithPunctuationIsInvalidated(): bool
	{
		$this->store->indexRebuild();
		
		$this->store->set('item1', 'test', tags: ['user:42', 'a-b c']);
		$this->store->set('item2', 'test', tags: ['a-b c', 'x.y@z']);
		
		$this->store->invalidateTags(['user:42']);
		$this->store->invalidateTags(['a-b c', 'x.y@z'], Store::MATCHING_ALL);
		
		$item1 = $this->store->get('item1', queue: false);
		$item2 = $this->store->get('item2', queue: false);
		
		try
		{
			return $item1 === null
				&& $item2 === null;
		}
		finally
		{
			$this->store->delete('item1');
			$this->store->delete('item2');
		}
	}
	
	public function invalidateTagsMatchingAll(): bool
	{
		$this->store->indexRebuild();
		
		$this->store->set('item1', 'test', tags: ['tag1', 'tag2']);
		$this->store->set('item2', 'test', tags: ['tag1']);
		
		$this->store->invalidateTags(['tag1', 'tag2'], Store::MATCHING_ALL);
		
		$item1 = $this->store->get('item1', queue: false);
		$item2 = $this->store->get('item2', queue: false);
		
		try
		{
			// only the item having all the tags is invalidated
			return $item1 === null
				&& $item2 === 'test';
		}
		finally
		{
			$this->store->delete('item1');
			$this->store->delete('item2');
		}
	}
	
	/**
	 * RULE: a large invalidation removes its matches in bounded calls - read
	 * a page at a time through a cursor, each page removed by calls of its
	 * own, so no single command holds the server for every match; and it
	 * answers that it did
	 */
	public function aLargeInvalidationRemovesItsMatchesInBoundedCalls(): bool
	{
		$store = $this->getStore(Store::class, ['invalidate_page' => 100]);
		$tag = 'boundedtag';
		for($i = 0; $i < 350; $i++)
		{
			$store->set('bounded-' . $i, 'test', 60, [$tag]);
		}
		
		$fcalls = $this->commandCalls('fcall');
		$reads = $this->commandCalls('FT.CURSOR|READ');
		$invalidated = $store->invalidateTags([$tag]);
		$fcalls = $this->commandCalls('fcall') - $fcalls;
		$reads = $this->commandCalls('FT.CURSOR|READ') - $reads;
		$left = $store->getClient()
			->rawCommand('FT.SEARCH', $store->getType(), '@tags:{' . $tag . '}', 'NOCONTENT', 'LIMIT', 0, 0);
		
		return $invalidated === true
			&& $store->get('bounded-0', queue: false) === null
			&& $store->get('bounded-175', queue: false) === null
			&& $store->get('bounded-349', queue: false) === null
			&& is_array($left) && (int)$left[0] === 0
			// the stamp, then a removal per page of 100
			&& $fcalls >= 1 + 4
			&& $reads >= 3;
	}
	
	/**
	 * RULE: a soft invalidation marks each item once, and its walk ends - the
	 * mark re-indexes an item, so the cursor delivers it again, and that
	 * delivery leaves it as it is (marked again, it would be delivered again,
	 * and the walk would only end once the marks expired)
	 */
	public function aSoftInvalidationMarksEachItemOnce(): bool
	{
		$store = $this->getStore(Store::class, ['invalidate_page' => 100]);
		$tag = 'softwalktag';
		for($i = 0; $i < 350; $i++)
		{
			$store->get('soft-walk-' . $i, fn(): string => 'value', 60, [$tag], stale: 60, soft: true, queue: false);
		}
		
		$hsets = $this->commandCalls('hset');
		$reads = $this->commandCalls('FT.CURSOR|READ');
		$invalidated = $store->invalidateTags([$tag]);
		$hsets = $this->commandCalls('hset') - $hsets;
		$reads = $this->commandCalls('FT.CURSOR|READ') - $reads;
		$first = $this->getStore(Store::class)->peek('soft-walk-0');
		$last = $this->getStore(Store::class)->peek('soft-walk-349');
		
		return $invalidated === true
			// one mark per item
			&& $hsets >= 350 && $hsets <= 351
			// every page twice at most: once found, once re-indexed by its mark
			&& $reads <= 2 * 4 + 1
			&& $first instanceof Stale && $first->invalidated === true
			&& $last instanceof Stale && $last->invalidated === true;
	}
	
	/**
	 * RULE: a removal call that fails is reported though the calls after it
	 * succeed - the invalidation answers false and logs the error (each call
	 * clears the client's last error, so read once per page it was gone)
	 */
	public function aFailedRemovalCallIsReported(): bool
	{
		$store = new class(...$this->storeArguments(['invalidate_page' => 100])) extends Store
		{
			// three removal calls for a page of 30
			public const int REMOVE_BATCH = 10;
			
			public array $logged = [];
			
			/**
			 * The first removal call asks for a function the server does not
			 * have: an error reply
			 */
			public function failFirstRemoval(): void
			{
				$this->functions = new class(static::LIBRARIES, $this->connection, $this->prefixer->getPrefix()) extends Functions
				{
					protected bool $failed = false;
					
					#[Override]
					public function call(
						string $function,
						array $keys = [],
						array $args = [],
						bool $readOnly = false,
						bool $long = false,
					): mixed
					{
						if($function === 'cache_remove_items' && $this->failed === false)
						{
							$this->failed = true;
							$function = 'cache_remove_items_gone';
						}
						
						return parent::call($function, $keys, $args, $readOnly, $long);
					}
				};
			}
			
			/**
			 * Kept, not reported: the test reads what was logged
			 */
			#[Override]
			public function log(
				...$event,
			): static
			{
				$this->logged[] = $event;
				
				return $this;
			}
		};
		$tag = 'failedtag';
		for($i = 0; $i < 30; $i++)
		{
			$store->set('failed-' . $i, 'test', 60, [$tag]);
		}
		
		$store->failFirstRemoval();
		$invalidated = $store->invalidateTags([$tag]);
		$left = $store->getClient()
			->rawCommand('FT.SEARCH', $store->getType(), '@tags:{' . $tag . '}', 'NOCONTENT', 'LIMIT', 0, 0);
		$logged = implode(' ', array_map(static fn(array $event): string => (string)($event[0] ?? ''), $store->logged));
		
		return $invalidated === false
			&& str_contains($logged, 'Function not found')
			// the calls after the failed one removed theirs
			&& is_array($left) && (int)$left[0] === 10;
	}
	
	public function clear(): bool
	{
		$this->store->indexRebuild();
		
		$array = [
			'stored' => true
		];
		
		$this->store->set(self::KEY_ITEM, $array);
		$this->store->clear();
		$result = $this->store->get(self::KEY_ITEM, queue: false);
		
		return $result === null;
	}
	
	/**
	 * Called by the runner after each test method
	 */
	#[Internal]
	#[Override]
	public function finalize(): void
	{
		// the wipe leaves a fresh, empty index (clear() rebuilds it), so the
		// next rule's items are indexed as they are written - dropped, the
		// rule's first invalidation would create it and search before
		// RediSearch's background scan reached the items written before it
		$this->store->clear();
		// a fresh store for the next rule: this one remembers its misses
		// (the invalidation guard - see KeyValue::rememberMiss())
		$this->store = $this->getStore(Store::class);
	}
	
	/**
	 * Called by the runner after all test methods have been invoked
	 */
	#[Internal]
	#[Override]
	public function deconstruct(): void
	{
		$this->store->getConnection()
			->disconnect();
	}
	
	/**
	 * A fresh store whose client counts its round trips (TraitBatch)
	 */
	protected function batchProbe(): ?KeyValueRedis
	{
		return $this->roundTripStore(Store::class);
	}
	
	/**
	 * A fresh store - another process (TraitStaleWhileRevalidate)
	 */
	protected function staleStore(): KeyValueRedis
	{
		return $this->guardStore();
	}
	
	/**
	 * A fresh store - another process (TraitInvalidationGuard)
	 */
	protected function guardStore(
		array $storeOptions = [],
	): KeyValueRedis
	{
		return $this->getStore(Store::class, $storeOptions);
	}
	
	/**
	 * How often the server ran $command so far (INFO commandstats - a command
	 * a Lua function runs is counted too)
	 */
	protected function commandCalls(
		string $command,
	): int
	{
		$stats = $this->store->getClient()
			->info('commandstats');
		
		return preg_match('/calls=(\d+)/', (string)($stats['cmdstat_' . $command] ?? ''), $match) === 1
			? (int)$match[1]
			: 0;
	}
	
	/**
	 * A fresh store that counts its round trips (TraitTagIndexMiss)
	 */
	protected function countingStore(): TagIndex
	{
		return new class(...$this->storeArguments()) extends Store
		{
			use TagIndexCounting;
		};
	}
}
