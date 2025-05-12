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
	 * Libraries
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
		$client->multi($this->_multiMode);
		// hSet can set multiple pairs of key => value, do not believe the PhpStorm Stub
		$client->hSet(
			$this->prefix($key, $this->getType()),
			self::KEY_DATA, $value,
			self::KEY_TAGS, implode(', ', $tags),
		);
		
		// set expire if needed
		if($ttl > 0)
		{
			$client->expire($key, $ttl);
		}
		$result = $client->exec();
		
		return $result[0] !== false;
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
		
		$type = $this->getType();
		
		// check if the index exists
		if($this->indexExists($type) === false)
		{
			// create the index
			$this->indexCreate($type);
			
			$client->rawCommand('FT.CONFIG', 'SET', 'MAXSEARCHRESULTS', -1);
		}
		
		$client->clearLastError();
		
		$this->_functionCall('cache_search_unlink_by_tags', [], [
			$type,
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
		$type = $this->getType();
		
		// check if the index exists
		if($this->indexExists($type))
		{
			// drop the index
			$this->indexDrop($type);
		}
		
		// create index again
		return $this->indexCreate($type);
	}
	
	/**
	 * @param string $type
	 *
	 * @return bool
	 */
	public function indexExists(string $type): bool
	{
		if(($client = $this->getClient()) === null)
		{
			return false;
		}
		
		// check if the index exists
		$indices = $client->rawCommand('FT._LIST');
		return in_array($type, $indices, true);
	}
	
	/**
	 * @param string $type
	 *
	 * @return bool
	 */
	public function indexDrop(string $type): bool
	{
		if(($client = $this->getClient()) === null)
		{
			return false;
		}
		
		// throws exception if index does not exist
		return $client->rawCommand('FT.DROPINDEX', $type, 'DD')
			=== self::STATUS_OK;
	}
	
	/**
	 * @param string $type
	 *
	 * @return bool
	 */
	public function indexCreate(string $type): bool
	{
		if(($client = $this->getClient()) === null)
		{
			return false;
		}
		
		// create index again
		return $client->rawCommand('FT.CREATE', ...[
			$type,
			'ON',
			'HASH',
			'PREFIX',
			1,
			$type,
			'SCHEMA',
			self::KEY_TAGS,
			'TAG',
		]) === self::STATUS_OK;
	}
}
