<?php
declare(strict_types=1);

namespace Ovos\Cache\Store;

use Ovos\ArrayObject;
use Ovos\Cache\Prefixer;
use Ovos\Cache\Store\KeyValue\Redis as Store;
use Override;
use Redis as RedisClient;
use RedisException;

use function array_diff;
use function array_merge;
use function array_push;
use function array_unique;
use function count;
use function explode;
use function implode;
use function is_array;

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
			$tags = $this->getCurrentTags($client, $id);
			
			$client->clearLastError();
			$client->multi($this->multiMode);
			$client->unlink($id);
			
			foreach($tags as $tag)
			{
				$tagId = $this->prefixer
					->prefix($tag, $this->getType(static::TYPE_TAGS));
				$client->hDel($tagId, $id);
			}
			
			$result = $client->exec();
			if($error = $client->getLastError())
			{
				$this->log($error);
			}
			
			if(is_array($result))
			{
				return $result[0] > 0; // unlink
			}
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
		
		try
		{
			$value = $this->serializer->serialize($value);
			$value = $this->compressor->compress($value);
			
			$currentTags = $this->getCurrentTags($client, $id);
			
			// if an item has some tags on it and a supplied array is empty,
			// then we should remove the "tags" field on the item
			if(count($currentTags) && count($tags) === 0)
			{
				$client->hDel($id, static::KEY_TAGS);
			}
			
			$client->clearLastError();
			$client->multi($this->multiMode);
			
			$args = [$id, static::KEY_DATA, $value];
			if(count($tags))
			{
				array_push($args,
					static::KEY_TAGS,
					implode(',', $tags)
				);
			}
			// @see https://redis.io/docs/latest/commands/hset/
			$client->hSet(...$args);
			
			// set expire if needed
			if($ttl)
			{
				$client->expire($id, $ttl);
			}
			
			$addTags = array_diff($tags, $currentTags);
			$removeTags = array_diff($currentTags, $tags);
			
			// process added tags
			foreach($addTags as $tag)
			{
				$tagId = $this->prefixer
					->prefix($tag, $this->getType(static::TYPE_TAGS));
				
				// add the id to the list of each tag
				$client->hSet($tagId,
					$key,
					null,
				);
				
				// expire the id in the list at the same time as id expires
				if($ttl)
				{
					$client->rawCommand('HEXPIRE', 
					$tagId,
						$ttl,
						'FIELDS',
						1,
						$key,
					);
				}
			}
			
			// process removed tags
			// remove the id from the list of each tag
			foreach($removeTags as $tag)
			{
				$tagId = $this->prefixer
					->prefix($tag, $this->getType(static::TYPE_TAGS));
				
				$client->hDel($tagId,
					$key,
				);
			}
			
			$result = $client->exec();
			if($error = $client->getLastError())
			{
				$this->log($error);
				
				return false;
			}
			
			if(is_array($result))
			{
				return $result[0] !== false; // hSet
			}
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
		
		$group = $this->getGroup() . Prefixer::SEPARATOR_PREFIX;
		$typeItems = static::TYPE_ITEMS . Prefixer::SEPARATOR_PREFIX;
		$typeTags = static::TYPE_TAGS . Prefixer::SEPARATOR_PREFIX;
		
		try
		{
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
	
	public function getIdsMatchingAnyTags(
		array $tags,
	): array
	{
		// return a unique list of ids matching any of the tags
		$ids = $this->getIdsMatchingAllTags($tags);
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
		if(($client = $this->getClient()) === null)
		{
			return [];
		}
		
		$ids = [];
		$group = $this->getGroup() . Prefixer::SEPARATOR_PREFIX;
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
		$group = $this->getGroup() . Prefixer::SEPARATOR_PREFIX;
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
	public function collectGarbage(): bool|int
	{
		if(($client = $this->getClient()) === null)
		{
			return false;
		}
		
		$tags = $this->getAllTags();
		$group = $this->getGroup() . Prefixer::SEPARATOR_PREFIX;
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
