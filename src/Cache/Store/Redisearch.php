<?php
declare(strict_types=1);

namespace Ovos\Cache\Store;

use Ovos\ArrayObject;
use Ovos\Cache\Stale;
use Ovos\Cache\Store\KeyValue\TagIndex;
use Override;
use Redis as RedisClient;
use RedisClusterException;
use RedisException;

use function array_chunk;
use function array_map;
use function array_slice;
use function count;
use function implode;
use function in_array;
use function is_array;
use function is_string;
use function max;
use function mb_strtolower;
use function preg_replace;
use function sprintf;
use function trim;

/**
 * Redisearch
 * 
 * The invalidation reads its matches through a cursor (FT.AGGREGATE), whose
 * results MAXAGGREGATERESULTS bounds: keep it unlimited, its default
 * https://redis.io/docs/latest/develop/interact/search-and-query/basic-constructs/configuration-parameters/#maxaggregateresults
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Redisearch extends TagIndex
{
	// Libraries
	/**
	 * An array of function libraries used by this class
	 */
	public const array LIBRARIES = [
		'cache' => 'Cache.lua',
	];
	
	/**
	 * Matches an invalidation reads per page of its cursor; each page is
	 * removed before the next is read (store option "invalidate_page")
	 */
	public const int INVALIDATE_PAGE = 2500;
	
	/**
	 * Items a removal call of an invalidation takes (cache_remove_items)
	 */
	public const int REMOVE_BATCH = 1000;
	
	protected int $invalidatePage = self::INVALIDATE_PAGE;
	
	#[Override]
	public function setStoreOptions(
		ArrayObject $options,
	): static
	{
		parent::setStoreOptions($options);
		
		if(($page = $options->offsetGet('invalidate_page')) !== null)
		{
			$this->invalidatePage = max(1, (int)$page);
		}
		
		return $this;
	}
	
	/**
	 * The write set() sends (see KeyValue\Redis::writeCall()): one step (Lua
	 * cache_guarded_hset) - refused when the key was invalidated, one of its
	 * tags stamped or the store cleared since the miss (an unstamped miss:
	 * when any of them carries a stamp); a write-through marks the key with a
	 * fresh epoch; a key that held no data (a tombstone) gets no expiry but
	 * the item's
	 */
	#[Override]
	protected function writeCall(
		string $id,
		string $key,
		mixed $value,
		int $ttl,
		array $tags,
		?array $miss,
	): array
	{
		// a soft value's item carries its stale time, so a tag invalidation
		// marks it instead of removing it (see KeyValue\Redis::KEY_SOFT)
		$soft = $value instanceof Stale && $value->isSoft()
			? [static::KEY_SOFT, $value->staleFor * 1000]
			: [];
		$value = $this->serializer
			->serialize($value);
		$value = $this->compressor
			->compress($value);
		
		$stampKeys = [$this->stampKey()];
		foreach($tags as $tag)
		{
			$stampKeys[] = $this->stampKey((string)$tag);
		}
		
		return ['cache_guarded_hset', [
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
		]];
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
		// a standalone client: the index, its cursor and the removals are one
		// server's - another one cannot invalidate, and the log says so
		if(($client = $this->getClient()) instanceof RedisClient === false)
		{
			if($client !== null)
			{
				$this->log(new RedisException(sprintf('Redisearch invalidation needs a standalone client, %s given',
					$client::class,
				)));
			}
			
			return false;
		}
		
		// no tags: nothing to invalidate
		if(count($tags) === 0)
		{
			return true;
		}
		
		$type = $this->getType();
		
		try
		{
			// check if the index exists
			if($this->indexExists($type) === false)
			{
				// create the index
				$this->indexCreate($type);
				
				// FT.SEARCH unlimited for a query on the index (the invalidation
				// itself reads through FT.AGGREGATE)
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
			
			// the stamps first: they refuse a value computed for the items
			// before this invalidation; the items found go, a soft one is
			// marked
			$this->stamp($tags);
			
			return $this->removeMatches($client, $type, $query, $hard);
		}
		catch(RedisException $exception)
		{
			$this->log($exception);
		}
		
		return false;
	}
	
	/**
	 * Removes the items $query matches in the index $type, a page at a time:
	 * the matches are read through a cursor (FT.AGGREGATE ... WITHCURSOR,
	 * invalidatePage per page) and each page goes in calls of
	 * cache_remove_items (REMOVE_BATCH items each) before the next is read
	 * - so no single command holds the server for every match, and other
	 * clients run between the pages. An item the removal marked softly is
	 * delivered once more (the mark re-indexes it) and left as it is.
	 * Whether every page was read and removed
	 */
	protected function removeMatches(
		RedisClient $client,
		string $type,
		string $query,
		bool $hard,
	): bool
	{
		$epoch = $this->newEpoch();
		$cursor = 0;
		$complete = false;
		// the first error a step met: every call clears the client's last one
		$error = null;
		$client->clearLastError();
		
		try
		{
			$reply = $client->rawCommand('FT.AGGREGATE', $type, $query,
				'LOAD', 1, '@__key',
				'WITHCURSOR', 'COUNT', $this->invalidatePage,
			);
			// a page: [[count, row, ...], cursor] - an error reply ends the walk
			while(is_array($reply) && is_array($reply[0] ?? null))
			{
				$cursor = (int)($reply[1] ?? 0);
				$keys = [];
				foreach(array_slice($reply[0], 1) as $row)
				{
					// a row: the field LOAD asked for and its value
					if(is_array($row) && ($row[0] ?? null) === '__key' && is_string($row[1] ?? null))
					{
						$keys[] = $row[1];
					}
				}
				foreach(array_chunk($keys, static::REMOVE_BATCH) as $batch)
				{
					// each reply read at once: the next call clears the client's
					// error, and a failed batch would go unseen
					if($this->functions
						->call('cache_remove_items', $batch, [
							$epoch,
							$this->invalidationWindowMs,
							$hard ? '1' : '0',
						], long: true) === false)
					{
						$error ??= $client->getLastError() ?? 'cache_remove_items: no reply';
					}
				}
				
				if($cursor === 0)
				{
					$complete = true;
					
					break;
				}
				
				$reply = $client->rawCommand('FT.CURSOR', 'READ', $type, $cursor);
			}
		}
		finally
		{
			// a walk left before its end frees its cursor now, not at the
			// server's idle timeout
			if($complete === false && $cursor !== 0)
			{
				$error ??= $client->getLastError();
				try
				{
					$client->rawCommand('FT.CURSOR', 'DEL', $type, $cursor);
				}
				catch(RedisException)
				{
					// the server drops it at its idle timeout then
				}
			}
		}
		
		// an error reply: the query, a cursor the server dropped, a removal
		$error ??= $client->getLastError();
		if($error !== null)
		{
			$this->log($error);
			
			return false;
		}
		
		return $complete;
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
