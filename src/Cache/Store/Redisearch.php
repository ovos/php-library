<?php
declare(strict_types=1);

namespace Ovos\Cache\Store;

use Ovos\Cache\Stale;
use Ovos\Cache\Store\KeyValue\Redis as Store;
use Override;
use RedisClusterException;
use RedisException;

use function array_map;
use function count;
use function implode;
use function in_array;
use function mb_strtolower;
use function preg_replace;
use function trim;

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
		// the miss this write follows, if any: it is guarded against an
		// invalidation since (see KeyValue::rememberMiss())
		$miss = $this->takeMiss($id);
		
		// a negative TTL expired the item at once - a delete it is
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
			$soft = $value instanceof Stale && $value->isSoft()
				? [static::KEY_SOFT, $value->staleFor * 1000]
				: [];
			$value = $this->serializer
				->serialize($value);
			$value = $this->compressor
				->compress($value);
			
			// one step (Lua cache_guarded_hset): refused when the key was
			// invalidated, one of its tags stamped or the store cleared since
			// the miss (an unstamped miss: the epoch only); a write-through
			// marks the key with a fresh epoch; a key that held no data (a
			// tombstone) gets no expiry but the item's
			$stampKeys = [$this->stampKey()];
			foreach($tags as $tag)
			{
				$stampKeys[] = $this->stampKey((string)$tag);
			}
			$client->clearLastError();
			$result = $this->functions
				->call('cache_guarded_hset', [
					$id,
					...$stampKeys,
				], [
					$miss['epoch'] ?? static::UNGUARDED,
					$miss === null ? '' : ($miss['stamp'] ?? ''),
					$ttl,
					$miss === null ? $this->writeThroughEpoch() : '',
					$this->invalidationWindowMs,
					static::KEY_DATA,
					$value,
					static::KEY_TAGS,
					implode(', ', $tags),
					...$soft,
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
			
			// its tombstone (see KeyValue\Redis::rememberMiss())
			return $this->tombstone($id) > 0;
		}
		// RedisClusterException does not extend RedisException, catch both
		// (this method is inherited by the RedisCluster store)
		catch(RedisException|RedisClusterException $exception)
		{
			$this->log($exception);
		}
		
		return false;
	}
	
	/**
	 * Invalidates the items carrying the tags; $hard: a soft value is a miss at
	 * once too (see Redis::invalidateTags())
	 */
	public function invalidateTags(
		array $tags,
		string $matching = self::MATCHING_ANY,
		bool $hard = false,
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
			$queryTags = array_map($this->queryTag(...), $tags);
			$query = $matching === static::MATCHING_ALL
				? '@tags:{' . implode('} @tags:{', $queryTags) . '}' // matches all of the tags
				: '@tags:{' . implode('|', $queryTags) . '}' // matches any of the tags
			;
			
			// the stamp first covers the values being computed now; the items
			// found leave tombstones
			$this->stamp($tags);
			if($this->functions
				->call('cache_search_unlink_by_tags', [], [
					$type,
					$query,
					$this->newEpoch(),
					$this->invalidationWindowMs,
					$hard ? '1' : '0',
				]) === false)
			{
				$this->logRefused('cache_search_unlink_by_tags');
				
				return false;
			}
			
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
		// the wipe stamps "cleared" in the same step (see clearedStamp())
		$keysUnlinked = parent::clear();
		if($keysUnlinked === false)
		{
			return false;
		}
		
		return $this->indexRebuild();
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
	 * A tag as the query syntax takes it (@tags:{...}): RediSearch reads
	 * punctuation and whitespace there as syntax - "user:42" or "a-b" made
	 * the whole FT.SEARCH a syntax error and the invalidation reached nothing
	 * - so every character but a letter, a digit or "_" is escaped
	 */
	protected function queryTag(
		string $tag,
	): string
	{
		return preg_replace('/[^\p{L}\p{N}_]/u', '\\\\$0', $tag) ?? $tag;
	}
	
	/**
	 * A tag's stamp as the index matches it: the TAG field is case-insensitive
	 * and trims its values, so invalidating "Foo" reaches "foo " - and must
	 * refuse a write tagged that way too
	 */
	#[Override]
	public function stampKey(
		?string $tag = null,
	): string
	{
		return parent::stampKey($tag === null
			? null
			: mb_strtolower(trim($tag)),
		);
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
