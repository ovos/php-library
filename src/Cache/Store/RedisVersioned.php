<?php
declare(strict_types=1);

namespace Ovos\Cache\Store;

use Ovos\ArrayObject;
use Ovos\Cache\Store\KeyValue\Redis as Store;
use Override;
use RedisClusterException;
use RedisException;

use function count;
use function explode;
use function implode;
use function is_array;
use function microtime;
use function sprintf;

/**
 * RedisVersioned
 *
 * Logical (rule based) tag invalidation: invalidateTags() appends one
 * rule to a stream instead of deleting the matched items - O(1)
 * regardless of whether 10 or 700000 items match. A read evaluates the
 * rules the item has not seen yet against its tags (server side in one
 * Lua call); stale items are lazily unlinked and physically expire by
 * their TTL.
 *
 * "rules_retention_s" is the default AND maximum item lifetime of the
 * store: an item must never outlive the rule that made it stale, or it
 * would resurrect once the rule is trimmed. A ttl of 0 means "as long
 * as the store allows" (= the retention); a ttl above the retention is
 * capped to it and logs a warning - raise the retention to at least the
 * longest item TTL in use.
 *
 * Ordering is causal, not clock based: every item is stamped with the
 * rules stream's last entry id at write time (its watermark), and only
 * rules with a newer id can invalidate it - no clock comparison happens
 * anywhere (stream ids are generated monotonically by the rules node).
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class RedisVersioned extends Store
{
	// Libraries
	/**
	 * An array of function libraries used by this class
	 * (Cache.lua provides the shared maintenance functions - cache_clear)
	 */
	public const array LIBRARIES = [
		'cache' => 'Cache.lua',
		'cache_versioned' => 'CacheVersioned.lua',
	];
	
	// Keys
	public const string KEY_MARK = 'mark';
	
	// Types
	public const string TYPE_RULES = 'rules';
	
	/**
	 * Invalidation rules (and therefore items) live at most this long.
	 * The default of 30 days sits above the longest TTL in use across
	 * the projects (typical items live ~3 days); note an explicit TTL is
	 * never extended - only a ttl of 0 inherits the retention.
	 * Unit: seconds
	 */
	protected int $rulesRetentionS = 2592000; // 30 days
	
	/**
	 * How long locally fetched rules may be reused before refetching
	 * (only the cluster read path uses this - a bounded staleness window)
	 * Unit: milliseconds
	 */
	protected int $rulesCacheMs = 1000;
	
	/**
	 * Locally cached parsed rules: [ms, sequence, mode, tags[]][]
	 */
	protected ?array $rules = null;
	
	protected ?float $rulesFetchedAtMs = null;
	
	/**
	 * The TTL-above-retention warning is logged once per instance
	 */
	protected bool $ttlWarned = false;
	
	#[Override]
	public function setStoreOptions(
		ArrayObject $options,
	): static
	{
		if(($retention = $options->offsetGet('rules_retention_s')) !== null)
		{
			$this->rulesRetentionS = (int)$retention;
		}
		if(($cacheMs = $options->offsetGet('rules_cache_ms')) !== null)
		{
			$this->rulesCacheMs = (int)$cacheMs;
		}
		
		return $this;
	}
	
	public function getRulesKey(): string
	{
		return $this->getType(static::TYPE_RULES);
	}
	
	#[Override]
	public function set(
		string $key,
		mixed $value,
		int $ttl = 0,
		array $tags = [],
	): bool
	{
		if($this->getClient() === null)
		{
			return false;
		}
		
		$id = $this->prefixer
			->prefix($key, $this->getType());
		
		// an explicitly requested TTL above the retention is capped to it
		// (see the class doc) - surface the surprise instead of hiding it
		if($ttl > $this->rulesRetentionS && $this->ttlWarned === false)
		{
			$this->ttlWarned = true;
			$this->log(sprintf(
				'Cache TTL %d s exceeds rules_retention_s %d s and is capped to it - raise the retention to keep longer-lived items.',
				$ttl,
				$this->rulesRetentionS,
			));
		}
		
		try
		{
			$value = $this->serializer
				->serialize($value);
			$value = $this->compressor
				->compress($value);
			
			$result = $this->setCall($id, $value, $tags, $ttl);
			
			return (int)$result === 1;
		}
		catch(RedisException|RedisClusterException $exception)
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
	
	/**
	 * Fetches an item: evaluates the invalidation rules server side via
	 * cache_versioned_validate (the data payload never goes through the
	 * Lua VM), then reads the data with a plain HGET only on a fresh hit
	 * - one copy, no Lua; stale items are lazily unlinked
	 */
	#[Override]
	protected function fetch(
		string $id,
	): mixed
	{
		if(($client = $this->getClient()) === null)
		{
			return null;
		}
		
		try
		{
			// evaluate the invalidation rules server side WITHOUT routing
			// the (potentially large) data payload through the Lua VM; only
			// the tiny verdict crosses the wire - the data is read with a
			// plain HGET on a fresh hit (one copy, no Lua)
			$hit = $this->functions
				->call('cache_versioned_validate', [
					$id,
					$this->getRulesKey(),
				]);
			
			if((int)$hit === 1)
			{
				$value = $client->hGet($id, static::KEY_DATA);
				
				if($value !== false)
				{
					$value = $this->compressor
						->decompress($value);
					return $this->serializer
						->unserialize($value);
				}
			}
		}
		catch(RedisException|RedisClusterException $exception)
		{
			$this->log($exception);
		}
		
		return null;
	}
	
	/**
	 * The write call: one Lua call reads the current watermark from the
	 * rules stream and stamps the item with it (two keys - the cluster
	 * store overrides this with a separate watermark read)
	 */
	protected function setCall(
		string $id,
		string $value,
		array $tags,
		int $ttl,
	): mixed
	{
		return $this->functions
			->call('cache_versioned_set', [
				$id,
				$this->getRulesKey(),
			], [
				$value,
				implode(',', $tags),
				$ttl * 1000, // ms
				$this->rulesRetentionS * 1000, // ms
			]);
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
		if(count($tags) === 0)
		{
			return false;
		}
		
		return $this->addRule($matching, $tags);
	}
	
	/**
	 * Logical clear: an "all" rule with no tags matches every item;
	 * the items remain in memory until their TTL (see clearPhysical())
	 */
	#[Override]
	public function clear(): bool
	{
		return $this->addRule(static::MATCHING_ALL, []);
	}
	
	/**
	 * Physical cleanup of the whole group (items and rules),
	 * normally not needed - clear() is logical and items expire by TTL
	 */
	public function clearPhysical(): bool|int
	{
		return parent::clear();
	}
	
	/**
	 * Appends one invalidation rule - O(1) regardless of the match count
	 */
	protected function addRule(
		string $mode,
		array $tags,
	): bool
	{
		if($this->getClient() === null)
		{
			return false;
		}
		
		try
		{
			// a single-slot FCALL on the rules key
			// (the stream generates the monotonic rule id)
			$result = $this->functions
				->call('cache_versioned_invalidate', [$this->getRulesKey()], [
					$mode,
					implode(',', $tags),
					$this->rulesRetentionS * 1000, // ms
				]);
			
			// the locally cached rules are stale now
			$this->rules = null;
			$this->rulesFetchedAtMs = null;
			
			return (int)$result === 1;
		}
		catch(RedisException|RedisClusterException $exception)
		{
			$this->log($exception);
		}
		
		return false;
	}
	
	/**
	 * Returns the parsed invalidation rules ([ms, sequence, mode, tags[]]),
	 * locally cached for rulesCacheMs (used by the cluster read path,
	 * where the rules cannot be evaluated server side)
	 */
	protected function getRules(): array
	{
		$nowMs = microtime(true) * 1000;
		
		if($this->rules !== null
			&& $this->rulesFetchedAtMs !== null
			&& $nowMs - $this->rulesFetchedAtMs < $this->rulesCacheMs)
		{
			return $this->rules;
		}
		
		if(($client = $this->getClient()) === null)
		{
			return [];
		}
		
		$rules = [];
		
		try
		{
			// [id => [field => value]], the ids are "<ms>-<sequence>"
			$entries = $client->xRange($this->getRulesKey(), '-', '+');
			
			if(is_array($entries))
			{
				foreach($entries as $id => $fields)
				{
					$id = explode('-', (string)$id);
					$tags = (string)($fields['tags'] ?? '');
					
					$rules[] = [
						(int)($id[0] ?? 0),
						(int)($id[1] ?? 0),
						(string)($fields['mode'] ?? ''),
						$tags === '' ? [] : explode(',', $tags),
					];
				}
			}
		}
		catch(RedisException|RedisClusterException $exception)
		{
			$this->log($exception);
			
			return [];
		}
		
		$this->rules = $rules;
		$this->rulesFetchedAtMs = $nowMs;
		
		return $rules;
	}
	
	/**
	 * PHP variant of the Lua rule evaluation (the cluster read path):
	 * only the rules newer than the item's watermark are considered,
	 * 'any' = tag intersection, 'all' = tag subset
	 * (an 'all' rule with no tags matches every item = a logical clear)
	 */
	protected function isStale(
		string $tags,
		string $mark,
	): bool
	{
		// an item without a watermark predates every rule
		$mark = explode('-', $mark);
		$markMs = (int)($mark[0] ?? 0);
		$markSequence = (int)($mark[1] ?? 0);
		
		$itemTags = [];
		if($tags !== '')
		{
			foreach(explode(',', $tags) as $tag)
			{
				$itemTags[$tag] = true;
			}
		}
		
		foreach($this->getRules() as [$ms, $sequence, $mode, $ruleTags])
		{
			// only the rules the item has not seen can invalidate it
			if($ms < $markMs
				|| ($ms === $markMs && $sequence <= $markSequence))
			{
				continue;
			}
			
			if($mode === static::MATCHING_ALL)
			{
				$matched = true;
				foreach($ruleTags as $tag)
				{
					if(isset($itemTags[$tag]) === false)
					{
						$matched = false;
						
						break;
					}
				}
				
				if($matched)
				{
					return true;
				}
			}
			else
			{
				foreach($ruleTags as $tag)
				{
					if(isset($itemTags[$tag]))
					{
						return true;
					}
				}
			}
		}
		
		return false;
	}
}
