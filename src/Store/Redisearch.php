<?php
declare(strict_types=1);

namespace Ovos\Store;

use RedisException;

use function in_array;
use function implode;
use function count;

/**
 * Redisearch
 * 
 * Important: please adjust MAXSEARCHRESULTS value to -1 on the cache instance
 * https://redis.io/docs/latest/develop/interact/search-and-query/basic-constructs/configuration-parameters/#maxsearchresults
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
class Redisearch extends Redis
{
	/**#@+
	 * Keys
	 */
	public const string KEY_TAGS = 'tags';
	/**#@-*/
	
	/**#@+
	 * Library
	 */
	/**
	 * The array of function libraries used by this lass
	 */
	public const array LIBRARIES = [
		'cache' => 'Lua' . DIRECTORY_SEPARATOR . 'Cache.lua',
		'cache_search' => 'Lua' . DIRECTORY_SEPARATOR . 'CacheSearch.lua',
	];
	/**#@-*/
	
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
		
		$value = $this->compress($this->serialize($value));
		
		// @see https://redis.io/docs/latest/commands/hset/
		$result = $client->hSet(
			$this->prefix($key, $this->getHashName()),
			self::KEY_DATA, $value,
			self::KEY_TAGS, implode(', ', $tags),
		);
		
		// set expire if needed
		if($ttl > 0)
		{
			$client->expire($key, $ttl);
		}
		
		return $result !== false;
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
		
		$hashName = $this->getHashName();
		
		// check if the index exists
		if($this->indexExists($hashName) === false)
		{
			// create the index
			$this->indexCreate($hashName);
			
			$client->rawCommand('FT.CONFIG', 'SET', 'MAXSEARCHRESULTS', -1);
		}
		
		$client->clearLastError();
		
		$this->_functionCall('cache_search_unlink_by_tags', [], [
			$hashName,
			implode('|', $tags),
		]);
		
		if($error = $client->getLastError())
		{
			throw new RedisException($error);
		}
		
		return true;
	}
	
	/**
	 * @return bool
	 */
	public function clear(): bool
	{
		$keysUnlinked = parent::clear();
		if($keysUnlinked === false)
		{
			return false;
		}
		
		return $this->indexRebuild();
	}
	
	/**
	 * @return bool
	 */
	public function indexRebuild(): bool
	{
		$hashName = $this->getHashName();
		
		// check if the index exists
		if($this->indexExists($hashName))
		{
			// drop the index
			$this->indexDrop($hashName);
		}
		
		// create index again
		return $this->indexCreate($hashName);
	}
	
	/**
	 * @param string $hashName
	 *
	 * @return bool
	 */
	public function indexExists(string $hashName): bool
	{
		if(($client = $this->getClient()) === null)
		{
			return false;
		}
		
		// check if the index exists
		$indices = $client->rawCommand('FT._LIST');
		return in_array($hashName, $indices, true);
	}
	
	/**
	 * @param string $hashName
	 *
	 * @return bool
	 */
	public function indexDrop(string $hashName): bool
	{
		if(($client = $this->getClient()) === null)
		{
			return false;
		}
		
		// throws exception if index does not exist
		return $client->rawCommand('FT.DROPINDEX', $hashName, 'DD')
			=== self::STATUS_OK;
	}
	
	/**
	 * @param string $hashName
	 *
	 * @return bool
	 */
	public function indexCreate(string $hashName): bool
	{
		if(($client = $this->getClient()) === null)
		{
			return false;
		}
		
		// create index again
		return $client->rawCommand('FT.CREATE', ...[
			$hashName,
			'ON',
			'HASH',
			'PREFIX',
			1,
			$hashName,
			'SCHEMA',
			self::KEY_TAGS,
			'TAG',
		]) === self::STATUS_OK;
	}
}
