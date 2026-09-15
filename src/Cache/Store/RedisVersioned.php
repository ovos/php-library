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
 * regardless of whether 10 or 700000 items match. A read fetches the item
 * with one HMGET and evaluates the rules it has not seen yet against its
 * tags in PHP, over a locally cached rule set (rules_cache_ms); stale
 * items are lazily unlinked and physically expire by their TTL.
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
 * The rules stream is the only record that an invalidation happened, so it
 * carries no TTL: under a volatile-* (or noeviction) policy it is then never
 * an eviction candidate - run one of those. Should it be lost anyway (an
 * allkeys-* policy, a DEL, a slot gone with a cluster node), the read path
 * fails safe: an item stamped with a rule the stream no longer remembers is
 * a miss, never a stale hit, at the price of recomputing the group once
 * (see isStale()).
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
	 * How long locally fetched rules may be reused before refetching - the
	 * read path (and the cluster write watermark) evaluate against this
	 * cached set, a bounded staleness window. Set to 0 to refetch on every
	 * read (exact, but it re-scans the backlog each time).
	 * Unit: milliseconds
	 */
	protected int $rulesCacheMs = 1000;
	
	/**
	 * Locally cached parsed rules: [ms, sequence, mode, tags[]][]
	 */
	protected ?array $rules = null;
	
	/**
	 * Whether the oldest rule held opened the stream (was appended to an
	 * empty one) - null when it did not say, having been written before the
	 * library recorded it. An item stamped older than such a rule has seen
	 * rules the stream has lost (see isStale())
	 */
	protected ?bool $rulesFirstOpened = null;
	
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
	 * Fetches an item with a single HMGET (data, tags, mark) and evaluates the
	 * rules it has not seen yet (newer than its mark) in PHP, against the
	 * locally cached rule set - see getRules() / isStale() - instead of
	 * re-scanning the rules stream on the server for every read. One round
	 * trip; the rules are fetched once per rules_cache_ms; a stale item is
	 * lazily unlinked.
	 *
	 * This trades exact invalidation for a bounded staleness window
	 * (rules_cache_ms) so a read no longer re-scans the backlog every time -
	 * set rules_cache_ms to 0 to re-read the rules on every read instead.
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
			// one HMGET brings data, tags and the watermark; the data is read
			// outside any Lua VM and decompressed only on a fresh verdict
			$item = $client->hMGet($id, [
				static::KEY_DATA,
				static::KEY_TAGS,
				static::KEY_MARK,
			]);
			
			// a missing "tags" field means the hash does not exist (an untagged
			// item still stores tags as an empty string); "data" must be present
			// too, to decompress
			if(is_array($item) === false
				|| ($item[static::KEY_TAGS] ?? false) === false
				|| ($item[static::KEY_DATA] ?? false) === false)
			{
				return null;
			}
			
			// only the rules newer than the item's mark are evaluated,
			// against the locally cached rule set
			if($this->isStale(
				(string)$item[static::KEY_TAGS],
				(string)$item[static::KEY_MARK],
			))
			{
				// lazily remove the stale item
				$client->unlink($id);
				
				return null;
			}
			
			$value = $this->compressor
				->decompress($item[static::KEY_DATA]);
			return $this->serializer
				->unserialize($value);
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
		// the rules stream is wiped too - drop the locally cached rules so a
		// write that follows is not evaluated against rules that no longer exist
		$this->resetRulesCache();
		
		return parent::clear();
	}
	
	/**
	 * Drops the locally cached rules so the next read refetches them - called
	 * whenever the rules stream changes under us (a new rule, or a physical wipe)
	 */
	protected function resetRulesCache(): void
	{
		$this->rules = null;
		$this->rulesFirstOpened = null;
		$this->rulesFetchedAtMs = null;
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
			$this->resetRulesCache();
			
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
	 * where the rules cannot be evaluated server side).
	 *
	 * Fails safe: if the rules cannot be (re)loaded it reuses the last-known
	 * set, or throws when none is cached - the caller then treats the item as
	 * a miss rather than serving data a rule it could not see might invalidate.
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
			// no client to refresh from: reuse the last-known rules if we
			// have them, otherwise fail safe (see the catch below) rather
			// than report "no rules" and let stale items read as fresh
			if($this->rules !== null)
			{
				return $this->rules;
			}
			
			throw new RedisException('Cannot load invalidation rules: no Redis client.');
		}
		
		$rules = [];
		$firstOpened = null;
		
		try
		{
			// [id => [field => value]], the ids are "<ms>-<sequence>"
			$entries = $client->xRange($this->getRulesKey(), '-', '+');
			
			if(is_array($entries))
			{
				foreach($entries as $id => $fields)
				{
					if(count($rules) === 0)
					{
						// the oldest entry: does it say it opened the stream?
						$first = $fields['first'] ?? null;
						$firstOpened = $first === null
							? null
							: (string)$first === '1';
					}
					
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
			// fail safe: never report an item as fresh just because the
			// rules could not be loaded. Reuse the last-known rules if we
			// have them (still honouring the invalidations seen so far);
			// otherwise let the error propagate so the read path treats the
			// item as a miss and re-resolves, instead of serving stale data.
			if($this->rules !== null)
			{
				$this->log($exception);
				
				return $this->rules;
			}
			
			throw $exception;
		}
		
		$this->rules = $rules;
		$this->rulesFirstOpened = $firstOpened;
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
		
		$rules = $this->getRules();
		
		// an item stamped with a real id has seen the stream hold at least
		// that rule: if the stream lost it, the rules in between may have
		// invalidated the item, and the only safe verdict is that they did
		if($this->rulesLostSince($markMs, $markSequence, $rules))
		{
			return true;
		}
		
		$itemTags = [];
		if($tags !== '')
		{
			foreach(explode(',', $tags) as $tag)
			{
				$itemTags[$tag] = true;
			}
		}
		
		foreach($rules as [$ms, $sequence, $mode, $ruleTags])
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
	
	/**
	 * Has the rules stream lost rules an item stamped with this watermark
	 * has seen?
	 *
	 * If nothing is held now, or the oldest rule held opened the stream and
	 * is newer than the stamp, whatever stood between is gone - evicted,
	 * deleted, or lost with a cluster slot. An item stamped 0-0 saw no rule,
	 * so nothing lost can concern it; and the first rule a group ever gets
	 * opens the stream without being a rebuild, which is what makes that
	 * exemption exact.
	 */
	protected function rulesLostSince(
		int $markMs,
		int $markSequence,
		array $rules,
	): bool
	{
		if($markMs === 0 && $markSequence === 0)
		{
			return false;
		}
		
		if(count($rules) === 0)
		{
			return true;
		}
		
		if($this->rulesFirstOpened !== true)
		{
			return false;
		}
		
		[$firstMs, $firstSequence] = $rules[0];
		
		return $firstMs > $markMs
			|| ($firstMs === $markMs && $firstSequence > $markSequence);
	}
}
