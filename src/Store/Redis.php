<?php
declare(strict_types=1);

namespace Ovos\Store;

use Ovos\ArrayObject;
use Ovos\Store\Redis\Cache;
use Redis as BaseRedis;
use RedisException;

use function is_int;
use function count;
use function explode;
use function implode;
use function array_push;
use function array_unique;
use function array_merge;
use function array_diff;

/**
 * Redis
 * 
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
class Redis extends Cache
{
	/**
	 * Types
	 */
	public const string TYPE_TAGS = 'tags';
	/**#@-*/
	
	/**
	 * Maintain clean tags = remove ids of invalidated items while invalidating them.
	 * Results in slower invalidation, at the same benefitting with consistent and compact data.
	 * If this option is off, make sure to enable garbage collector (can run as CLI once at night).
	 * 
	 * @var bool
	 */
	protected bool $_cleanTags = false;
	
	/**
	 * @param ArrayObject $options
	 *
	 * @return self
	 */
	public function setStoreOptions(ArrayObject $options): self
	{
		if(($cleanTags = $options->offsetGet('clean_tags')) !== null) // true or false
		{
			$this->setCleanTags($cleanTags);
		}
		
		return $this;
	}
	
	/**
	 * @param bool $cleanTags
	 *
	 * @return self
	 */
	public function setCleanTags(bool $cleanTags): self
	{
		$this->_cleanTags = $cleanTags;
		
		return $this;
	}
	
	/**
	 * @return bool
	 */
	public function getCleanTags(): bool
	{
		return $this->_cleanTags;
	}
	
	/**
	 * @param BaseRedis $client
	 * @param string $id
	 *
	 * @return array
	 */
	protected function _getCurrentTags(BaseRedis $client, string $id): array
	{
		try
		{
			if(($itemTags = $client->hGet(
				$id,
				self::KEY_TAGS,
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
	
	/**
	 * @param string $key
	 *
	 * @return ?array
	 */
	public function getTags(string $key): ?array
	{
		if(($client = $this->getClient()) === null)
		{
			return null;
		}
		
		try
		{
			$id = $this->prefix($key, $this->getType());
			
			return $this->_getCurrentTags($client, $id);
		}
		catch(RedisException $exception)
		{
			$this->log($exception);
		}
		
		return null;
	}
	
	/**
	 * @param string $key
	 *
	 * @return null|bool
	 */
	public function delete(string $key): null|bool
	{
		if(($client = $this->getClient()) === null)
		{
			return null;
		}
		
		try
		{
			$id = $this->prefix($key, $this->getType());
			$tags = $this->_getCurrentTags($client, $id);
			
			$client->clearLastError();
			$client->multi($this->_multiMode);
			$client->unlink($id);
			
			foreach($tags as $tag)
			{
				$tagId = $this->prefix($tag, $this->getType(self::TYPE_TAGS));
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
	
	/**
	 * @param string $key
	 * @param mixed $value
	 * @param int $ttl
	 * @param array $tags
	 * 
	 * @return bool
	 */
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
		
		$id = $this->prefix($key, $this->getType());
		
		try
		{
			$value = $this->compress($this->serialize($value));
			
			$currentTags = $this->_getCurrentTags($client, $id);
			
			// if an item has some tags on it and a supplied array is empty,
			// then we should remove the "tags" field on the item
			if(count($currentTags) && count($tags) === 0)
			{
				$client->hDel($id, self::KEY_TAGS);
			}
			
			$client->clearLastError();
			$client->multi($this->_multiMode);
			
			$args = [$id, self::KEY_DATA, $value];
			if(count($tags))
			{
				array_push($args,
					self::KEY_TAGS,
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
				$tagId = $this->prefix($tag, $this->getType(self::TYPE_TAGS));
				
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
				$tagId = $this->prefix($tag, $this->getType(self::TYPE_TAGS));
				
				$client->hDel($tagId,
					$key,
				);
			}
			
			$result = $client->exec();
			if($error = $client->getLastError())
			{
				$this->log($error);
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
			$this->releaseActiveLock($key, $id);
		}
		
		return false;
	}
	
	/**
	 * @param array $tags
	 *
	 * @return bool
	 */
	public function invalidateTags(array $tags): bool
	{
		if(($client = $this->getClient()) === null)
		{
			return false;
		}
		
		if(count($tags) === 0)
		{
			return false;
		}
		
		$group = $this->getGroup() . self::SEPARATOR_PREFIX;
		$typeItems = self::TYPE_ITEMS . self::SEPARATOR_PREFIX;
		$typeTags = self::TYPE_TAGS . self::SEPARATOR_PREFIX;
		
		try
		{
			$ids = $this->getIdsMatchingAnyTags($tags);
			
			$client->clearLastError();
			
			// this is an option functionality, which is not required
			// at the cost of speed on invalidation; it keeps a database smaller (clean)
			// by removing ids from tags
			if($this->_cleanTags === true)
			{
				$this->_batchFunctionCall('cache_unlink_clean_tags', $ids, [
					$group,
					$typeItems,
					$typeTags,
					self::KEY_TAGS,
				], long: true);
			}
			
			if($error = $client->getLastError())
			{
				$this->log($error);
			}
			
			$client->clearLastError();
			
			foreach($tags as $tag)
			{
				$this->_functionCall('cache_unlink_by_tag', [], [
					$group,
					$tag,
					$typeItems,
					$typeTags,
				], long: true);
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
	 * @param array $tags
	 *
	 * @return array
	 */
	public function getIdsMatchingAnyTags(array $tags): array
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
	
	/**
	 * @param array $tags
	 *
	 * @return array
	 */
	public function getIdsMatchingAllTags(array $tags): array
	{
		if(($client = $this->getClient()) === null)
		{
			return [];
		}
		
		$ids = [];
		$group = $this->getGroup() . self::SEPARATOR_PREFIX;
		$typeTags = self::TYPE_TAGS . self::SEPARATOR_PREFIX;
		
		try
		{
			$client->clearLastError();
			
			foreach($tags as $tag)
			{
				$results = $this->_functionCall('cache_get_ids_by_tag', [], [
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
	 * 
	 * @return array
	 */
	public function getAllTags(): array
	{
		if(($client = $this->getClient()) === null)
		{
			return [];
		}
		
		$tags = [];
		$group = $this->getGroup() . self::SEPARATOR_PREFIX;
		$typeTags = self::TYPE_TAGS . self::SEPARATOR_PREFIX;
		
		try
		{
			$client->clearLastError();
			
			$results = $this->_functionCall('cache_get_tags', [], [
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
	 * 
	 * @return bool|int
	 */
	public function collectGarbage(): bool|int
	{
		if(($client = $this->getClient()) === null)
		{
			return false;
		}
		
		$tags = $this->getAllTags();
		$group = $this->getGroup() . self::SEPARATOR_PREFIX;
		$typeItems = self::TYPE_ITEMS . self::SEPARATOR_PREFIX;
		$typeTags = self::TYPE_TAGS . self::SEPARATOR_PREFIX;
		$count = 0;
		
		try
		{
			$client->clearLastError();
			
			foreach($tags as $tag)
			{
				$result = $this->_functionCall('cache_clean_tag', [], [
					$group,
					$tag,
					$typeItems,
					$typeTags,
				], long: true);
				
				if(is_int($count))
				{
					$count+= $result;
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
			
			return false;
		}
		
		return $count;
	}
}
