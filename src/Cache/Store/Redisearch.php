<?php
declare(strict_types=1);

namespace Ovos\Cache\Store;

use Ovos\Cache\Store\KeyValue\Redis as Store;
use Override;
use RedisClusterException;
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
 * @author Marcin Gil <mg@ovos.at>
 */
class Redisearch extends Store
{
	// Libraries
	/**
	 * An array of function libraries used by this class
	 */
	public const array LIBRARIES = [
		'cache' => 'Cache.lua',
		'cache_search' => 'CacheSearch.lua',
	];
	
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
			$value = $this->serializer
				->serialize($value);
			$value = $this->compressor
				->compress($value);
			
			$client->clearLastError();
			$client->multi($this->multiMode);
			// hSet can set multiple pairs of key => value, do not believe the PhpStorm Stub
			// @see https://redis.io/docs/latest/commands/hset/
			$client->hSet(
				$id,
				static::KEY_DATA, $value,
				static::KEY_TAGS, implode(', ', $tags),
			);
			
			// set expire if needed
			if($ttl > 0)
			{
				$client->expire($id, $ttl);
			}
			$result = $client->exec();
			if($error = $client->getLastError())
			{
				$this->log($error);
			}
			
			return $result[0] !== false;
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
			$result = $client->unlink($id);
			
			return $result > 0;
		}
		// RedisClusterException does not extend RedisException, catch both
		// (this method is inherited by the RedisCluster store)
		catch(RedisException|RedisClusterException $exception)
		{
			$this->log($exception);
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
			return false;
		}
		
		$type = $this->getType();
		
		try
		{
			// check if the index exists
			if($this->indexExists($type) === false)
			{
				// create the index
				$this->indexCreate($type);
				
				$client->rawCommand('FT.CONFIG', 'SET', 'MAXSEARCHRESULTS', -1);
			}
			
			$client->clearLastError();
			
			/**
			 * Matching modes
			 * * any: @tags:{New York|Los Angeles|Barcelona}
			 * * all: @tags:{New York} @tags:{Los Angeles} @tags:{Barcelona}
			 */
			$query = $matching === static::MATCHING_ALL
				? '@tags:{' . implode('} @tags:{', $tags) . '}' // matches all of the tags
				: '@tags:{' . implode('|', $tags) . '}' // matches any of the tags
			;
			
			$this->functions
				->call('cache_search_unlink_by_tags', [], [
					$type,
					$query,
				]);
			
			return true;
		}
		catch(RedisException $exception)
		{
			$this->log($exception);
		}
		
		return false;
	}
	
	public function clear(): bool
	{
		$keysUnlinked = parent::clear();
		if($keysUnlinked === false)
		{
			return false;
		}
		
		return $this->indexRebuild();
	}
	
	public function indexRebuild(): bool
	{
		$type = $this->getType();
		
		try
		{
			// check if the index exists
			if($this->indexExists($type))
			{
				// drop the index
				$this->indexDrop($type);
			}
			
			// create index again
			return $this->indexCreate($type);
		}
		catch(RedisException $exception)
		{
			$this->log($exception);
		}
		
		return false;
	}
	
	public function indexExists(
		string $type,
	): bool
	{
		if(($client = $this->getClient()) === null)
		{
			return false;
		}
		
		try
		{
			// check if the index exists
			$indices = $client->rawCommand('FT._LIST');
			return in_array($type, $indices, true);
		}
		catch(RedisException $exception)
		{
			$this->log($exception);
		}
		
		return false;
	}
	
	public function indexDrop(
		string $type,
	): bool
	{
		if(($client = $this->getClient()) === null)
		{
			return false;
		}
		
		try
		{
			// throws exception if index does not exist
			return $client->rawCommand('FT.DROPINDEX',
				$type,
				'DD',
			) === static::STATUS_OK;
		}
		catch(RedisException $exception)
		{
			$this->log($exception);
		}
		
		return false;
	}
	
	public function indexCreate(
		string $type,
	): bool
	{
		if(($client = $this->getClient()) === null)
		{
			return false;
		}
		
		try
		{
			// create index again
			return $client->rawCommand('FT.CREATE', ...[
				$type,
				'ON',
				'HASH',
				'PREFIX',
				1,
				$type,
				'SCHEMA',
				static::KEY_TAGS,
				'TAG',
			]) === static::STATUS_OK;
		}
		catch(RedisException $exception)
		{
			$this->log($exception);
		}
		
		return false;
	}
}
