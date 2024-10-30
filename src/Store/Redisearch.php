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
	public const KEY_TAGS = 'tags';
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
		
		// check if index exists
		if($this->indexExists($hashName) === false)
		{
			// create the index
			$this->indexCreate($hashName);
			
			$client->rawCommand('FT.CONFIG', 'SET', 'MAXSEARCHRESULTS', -1);
		}
		
		$script = '';
		$script.= $this->getFunction(self::FUNCTION_BATCHES);
		$script.= "
			local index = ARGV[1]
			local tags = ARGV[2]
			local batchSize = 10000
			local offset = 0
			
			while true do
				-- Perform the FT.SEARCH with batching
				local searchCommand = {'FT.SEARCH', index, '@tags:{' .. tags .. '}', 'LIMIT', offset, batchSize}
				local result = redis.call(unpack(searchCommand))
				
				-- quit if there are no more matches
				local totalResults = tonumber(result[1])
				if totalResults == 0 then
					break
				end	
				
				local rems = {}
				
				-- loop every second item, skipping the first which is totalResults
				for i = 2, #result, 2 do
					-- local id = result[i] -- The document ID/key
					-- local fields = result[i+1] -- The document's fields and values (array)
					
					table.insert(rems, result[i]) -- save for removal after the loop
				end
				
				-- remove hash keys which no longer exist
				if #rems > 0 then
					for from, to in batches(#rems) do
						redis.call('UNLINK', unpack(rems, from, to))
					end
				end
			end
		";
		
		$client->clearLastError();
		
		$args = [$hashName, implode('|', $tags)];
		$client->eval($script, $args, 0);
		
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
		if(($client = $this->getClient()) === null)
		{
			return false;
		}
		
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
		if(($client = $this->getClient()) === null)
		{
			return false;
		}
		
		$hashName = $this->getHashName();
		
		// check if index exists
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
		
		// check if index exists
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
