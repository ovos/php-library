<?php
declare(strict_types=1);

namespace Ovos\Cache\Store;

use Ovos\ArrayObject;
use Ovos\Cache\Prefixer;
use Ovos\Cache\Stale;
use Ovos\Cache\Store\KeyValue\Redis as Store;
use Override;
use Redis as RedisClient;
use RedisException;

use function array_intersect;
use function array_merge;
use function array_unique;
use function array_values;
use function count;
use function explode;
use function is_array;
use function is_int;
use function sprintf;

/**
 * Redis
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Redis extends Store
{
	// Types
	public const string TYPE_TAGS = 'tags';
	
	/**
	 * Maintain clean tags = remove ids of invalidated items while invalidating them.
	 * Results in slower invalidation, at the same benefitting with consistent and compact data.
	 * If this option is off, make sure to enable garbage collector (can run as CLI once at night).
	 */
	protected bool $cleanTags = false;
	
	public function setStoreOptions(
		ArrayObject $options,
	): static
	{
		parent::setStoreOptions($options);
		
		if(($cleanTags = $options->offsetGet('clean_tags')) !== null) // true or false
		{
			$this->setCleanTags($cleanTags);
		}
		
		return $this;
	}
	
	public function setCleanTags(
		bool $cleanTags,
	): static
	{
		$this->cleanTags = $cleanTags;
		
		return $this;
	}
	
	public function getCleanTags(): bool
	{
		return $this->cleanTags;
	}
	
	/**
	 * The base every stored key starts with, as a Lua key-prefix argument:
	 * the group when one is set, else the bare prefix. set() keys items and
	 * tags via the Prefixer, which FALLS BACK to the prefix when no group is
	 * set - string-concatenating getGroup() (null becomes '') does not, which
	 * made every tag read/invalidate on a group-less store (the way
	 * applications run it) look for keys under ':tags:…' that set() had
	 * written under '<prefix>:tags:…', silently matching nothing.
	 */
	protected function getKeyBase(): string
	{
		$base = $this->getGroup() ?? $this->prefixer->getPrefix();
		
		return $base !== null
			? $base . Prefixer::SEPARATOR_PREFIX
			: '';
	}
	
	protected function getCurrentTags(
		RedisClient $client,
		string $id,
	): array
	{
		try
		{
			if(($itemTags = $client->hGet(
				$id,
				static::KEY_TAGS,
			)) !== false)
			{
				return explode(',', $itemTags);
			}
		}
		catch(RedisException $exception)
		{
			$this->log($exception);
		}
		
		return [];
	}
	
	public function getTags(
		string $key,
	): ?array
	{
		if(($client = $this->getClient()) === null)
		{
			return null;
		}
		
		try
		{
			$id = $this->prefixer
				->prefix($key, $this->getType());
			
			return $this->getCurrentTags($client, $id);
		}
		catch(RedisException $exception)
		{
			$this->log($exception);
		}
		
		return null;
	}
	
	public function delete(
		string $key,
	): bool
	{
		if(($client = $this->getClient()) === null)
		{
			return false;
		}
		
		try
		{
			$id = $this->prefixer
				->prefix($key, $this->getType());
			// this instance's own delete is no race (see KeyValue\Redis::tombstone())
			unset($this->misses[$id]);
			
			// one step (Lua cache_delete_item): the item leaves its tags' indexes
			// (the field is its key, as set() writes it) and becomes a tombstone
			// (see KeyValue::rememberMiss()) - a write landing between two steps
			// would lose its tag entry to the HDEL
			$client->clearLastError();
			$existed = $this->functions
				->call('cache_delete_item', [$id], [
					$this->newEpoch(),
					$this->invalidationWindowMs,
					$this->getType(static::TYPE_TAGS) . Prefixer::SEPARATOR_PREFIX,
					$key,
				]);
			if(is_int($existed))
			{
				return $existed > 0;
			}
			
			// the call failed: the item goes anyway (see KeyValue\Redis::tombstone())
			$this->log(sprintf('cache_delete_item failed for "%s": %s',
				$id,
				$client->getLastError() ?? 'no reply',
			));
			
			return $this->unlink($id) > 0;
		}
		catch(RedisException $exception)
		{
			$this->log($exception);
		}
		
		return false;
	}
	
	#[Override]
	public function set(
		string $key,
		mixed $value,
		int $ttl = 0,
		array $tags = [],
	): bool
	{
		if(($client = $this->getClient()) === null)
		{
			return false;
		}
		
		$id = $this->prefixer
			->prefix($key, $this->getType());
		// the miss this write follows, if any: it is guarded against an
		// invalidation since (see KeyValue::rememberMiss())
		$miss = $this->takeMiss($id);
		
		// a negative TTL expired the item at once (EXPIRE with a negative
		// value deletes the key) - a delete it is
		if($ttl < 0)
		{
			try
			{
				return $this->delete($key);
			}
			finally
			{
				$this->getMemoLock()
					->releaseActiveLock($id);
			}
		}
		
		try
		{
			// a soft value's item carries its stale time, so a tag invalidation
			// marks it instead of tombstoning it (see KeyValue\Redis::KEY_SOFT)
			$soft = $value instanceof Stale && $value->soft
				? $value->staleFor * 1000
				: null;
			$value = $this->serializer->serialize($value);
			$value = $this->compressor->compress($value);
			
			// the item and its tag index in one step (Lua cache_set): the
			// item's tags are read there, an item written without tags drops
			// the field, every tag field's HEXPIRE follows the item's TTL, the
			// tags it no longer has release it - a guarded write is refused
			// when the key was invalidated, a tag of it stamped or the store
			// cleared since its miss (an unstamped miss: the epoch only), and a
			// write-through marks the key with a fresh epoch; a soft value goes
			// through cache_set_soft - the same write, its stale time first
			$client->clearLastError();
			$result = $this->functions
				->call($soft === null ? 'cache_set' : 'cache_set_soft', [
					$id,
					$this->stampKey(),
				], [
					...($soft === null ? [] : [$soft]),
					$miss['epoch'] ?? static::UNGUARDED,
					$miss === null ? '' : ($miss['stamp'] ?? ''),
					$ttl,
					$key,
					$this->getType(static::TYPE_TAGS) . Prefixer::SEPARATOR_PREFIX,
					$this->getType(static::TYPE_INVALIDATED) . Prefixer::SEPARATOR_PREFIX,
					$miss === null ? $this->writeThroughEpoch() : '',
					$this->invalidationWindowMs,
					$value,
					...array_values(array_unique($tags)),
				]);
			if($result === false && ($error = $client->getLastError()))
			{
				$this->log($error);
			}
			
			return (int)$result === 1;
		}
		catch(RedisException $exception)
		{
			$this->log($exception);
		}
		finally
		{
			$this->getMemoLock()
				->debug('save: ' . $id)
				->releaseActiveLock($id);
		}
		
		return false;
	}
	
	public function invalidateTags(
		array $tags,
		string $matching = self::MATCHING_ANY,
	): bool
	{
		if(($client = $this->getClient()) === null)
		{
			return false;
		}
		
		if(count($tags) === 0)
		{
			return true;
		}
		
		// invalidate only the items having all of the tags
		if($matching === static::MATCHING_ALL)
		{
			return $this->invalidateTagsMatchingAll($tags);
		}
		
		$group = $this->getKeyBase();
		$typeItems = static::TYPE_ITEMS . Prefixer::SEPARATOR_PREFIX;
		$typeTags = static::TYPE_TAGS . Prefixer::SEPARATOR_PREFIX;
		// the items invalidated leave tombstones; the stamp first covers the
		// ones being computed now, not in the tag index yet
		$epoch = $this->newEpoch();
		
		try
		{
			$this->stamp($tags);
			
			// this is an option functionality, which is not required
			// at the cost of speed on invalidation; it keeps a database smaller (clean)
			// by removing ids from tags
			if($this->cleanTags === true)
			{
				$ids = $this->getIdsMatchingAnyTags($tags);
				$countIds = count($ids);
				
				if($countIds)
				{
					$client->clearLastError();
					
					$this->functions
						->batchCall('cache_unlink_clean_tags', $ids, [
							$group,
							$typeItems,
							$typeTags,
							static::KEY_TAGS,
							$epoch,
							$this->invalidationWindowMs,
						], long: true);
					
					if($error = $client->getLastError())
					{
						$this->log($error);
					}
				}
			}
			
			$client->clearLastError();
			
			foreach($tags as $tag)
			{
				// initialize the batch cursor for each tag
				$cursor = '0';
				
				do
				{
					$result = $this->functions
						->call('cache_unlink_by_tag', [], [
							$group,
							$tag,
							$typeItems,
							$typeTags,
							$cursor,
							$epoch,
							$this->invalidationWindowMs,
						], long: true);
					
					if(is_array($result) === false
						|| count($result) !== 2)
					{
						break; // stop processing this tag if the result is invalid
					}
					
					$cursor = $result[1];
				}
				// continue as long as the cursor is not '0' (meaning there are more items to scan)
				while($cursor !== '0');
			}
			
			if($error = $client->getLastError())
			{
				$this->log($error);
			}
			
			return true;
		}
		catch(RedisException $exception)
		{
			$this->log($exception);
		}
		
		return false;
	}
	
	/**
	 * Invalidates only the items having all of the tags given
	 */
	protected function invalidateTagsMatchingAll(
		array $tags,
	): bool
	{
		if(($client = $this->getClient()) === null)
		{
			return false;
		}
		
		// every tag is stamped - a value being computed now refuses for any of
		// them (one carrying a single tag would have survived; refusing a
		// write is always safe)
		$this->stamp($tags);
		
		$ids = $this->getIdsMatchingAllTags($tags);
		
		if(count($ids) === 0)
		{
			return true;
		}
		
		$group = $this->getKeyBase();
		$typeItems = static::TYPE_ITEMS . Prefixer::SEPARATOR_PREFIX;
		$typeTags = static::TYPE_TAGS . Prefixer::SEPARATOR_PREFIX;
		$epoch = $this->newEpoch();
		
		try
		{
			$client->clearLastError();
			
			if($this->cleanTags === true)
			{
				// unlink the ids and remove them from all of their tags
				$this->functions
					->batchCall('cache_unlink_clean_tags', $ids, [
						$group,
						$typeItems,
						$typeTags,
						static::KEY_TAGS,
						$epoch,
						$this->invalidationWindowMs,
					], long: true);
			}
			else
			{
				// unlink the ids and remove them from the matched tags only;
				// references left in other tags are removed by the garbage collector
				foreach($tags as $tag)
				{
					$this->functions
						->batchCall('cache_unlink_ids_by_tag', $ids, [
							$group,
							$tag,
							$typeItems,
							$typeTags,
							$epoch,
							$this->invalidationWindowMs,
						], long: true);
				}
			}
			
			if($error = $client->getLastError())
			{
				$this->log($error);
			}
			
			return true;
		}
		catch(RedisException $exception)
		{
			$this->log($exception);
		}
		
		return false;
	}
	
	/**
	 * The physical wipe stamps "cleared" in the same step: a value computed
	 * before the wipe and written after it is refused (its miss came first)
	 */
	#[Override]
	protected function clearedStamp(): array
	{
		return $this->isGuarded()
			? [$this->stampKey(), $this->invalidationWindowMs]
			: [];
	}
	
	/**
	 * A miss stamps the server time: a tag stamped (or the store cleared)
	 * after it refuses the write that follows
	 */
	#[Override]
	protected function missStamp(): ?string
	{
		return $this->serverTime();
	}
	
	public function getIdsMatchingAnyTags(
		array $tags,
	): array
	{
		// return a unique list of ids matching any of the tags
		$ids = $this->getIdsGroupedByTags($tags);
		$countIds = count($ids);
		
		if($countIds === 1)
		{
			return array_unique($ids[0]);
		}
		if($countIds > 1)
		{
			return array_unique(array_merge(...$ids));
		}
		
		return [];
	}
	
	public function getIdsMatchingAllTags(
		array $tags,
	): array
	{
		// return a unique list of ids matching all of the tags
		$ids = $this->getIdsGroupedByTags($tags);
		$countIds = count($ids);
		
		// a missing list for any of the tags means no id can match them all
		if($countIds === 0 || $countIds < count($tags))
		{
			return [];
		}
		
		if($countIds === 1)
		{
			return array_unique($ids[0]);
		}
		
		return array_unique(array_intersect(...$ids));
	}
	
	/**
	 * Returns lists of ids grouped by each of the tags given
	 */
	public function getIdsGroupedByTags(
		array $tags,
	): array
	{
		if(($client = $this->getClient()) === null)
		{
			return [];
		}
		
		$ids = [];
		$group = $this->getKeyBase();
		$typeTags = static::TYPE_TAGS . Prefixer::SEPARATOR_PREFIX;
		
		try
		{
			$client->clearLastError();
			
			foreach($tags as $tag)
			{
				$results = $this->functions
					->call('cache_get_ids_by_tag', [], [
						$group,
						$tag,
						$typeTags,
					], true);
				
				if(is_array($results))
				{
					$ids[] = $results;
				}
			}
			
			if($error = $client->getLastError())
			{
				$this->log($error);
			}
		}
		catch(RedisException $exception)
		{
			$this->log($exception);
		}
		
		return $ids;
	}
	
	/**
	 * Returns a list of all tags
	 */
	public function getAllTags(): array
	{
		if(($client = $this->getClient()) === null)
		{
			return [];
		}
		
		$tags = [];
		$group = $this->getKeyBase();
		$typeTags = static::TYPE_TAGS . Prefixer::SEPARATOR_PREFIX;
		
		try
		{
			$client->clearLastError();
			
			$results = $this->functions
				->call('cache_get_tags', [], [
					$group,
					$typeTags,
				], true);
			
			if($error = $client->getLastError())
			{
				$this->log($error);
			}
			
			if(is_array($results))
			{
				$tags = array_unique($results);
			}
		}
		catch(RedisException $exception)
		{
			$this->log($exception);
		}
		
		return $tags;
	}
	
	/**
	 * Throws exception on purpose, this method is not meant to be used by normal users
	 */
	#[Override]
	public function collectGarbage(): bool|int
	{
		if(($client = $this->getClient()) === null)
		{
			return false;
		}
		
		$tags = $this->getAllTags();
		$group = $this->getKeyBase();
		$typeItems = static::TYPE_ITEMS . Prefixer::SEPARATOR_PREFIX;
		$typeTags = static::TYPE_TAGS . Prefixer::SEPARATOR_PREFIX;
		$count = 0;
		
		try
		{
			$client->clearLastError();
			
			foreach($tags as $tag)
			{
				// initialize the batch cursor for each tag
				$cursor = '0';
				
				do
				{
					$result = $this->functions
						->call('cache_clean_tag', [], [
							$group,
							$tag,
							$typeItems,
							$typeTags,
							$cursor,
						], long: true);
					
					if(is_array($result) === false
						|| count($result) !== 2)
					{
						break; // stop processing this tag if the result is invalid
					}
					
					$count += (int)$result[0];
					$cursor = $result[1];
				}
				// continue as long as the cursor is not '0' (meaning there are more items to scan)
				while($cursor !== '0');
			}
			
			if($error = $client->getLastError())
			{
				$this->log($error);
			}
		}
		catch(RedisException $exception)
		{
			$this->log($exception);
			
			return false;
		}
		
		return $count;
	}
}
