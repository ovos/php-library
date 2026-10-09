<?php
declare(strict_types=1);

namespace Ovos\Cache\Store;

use Ovos\ArrayObject;
use Ovos\Cache\Stale;
use Ovos\Cache\Store\KeyValue\Redis as Store;
use Ovos\Cache\Versioned\Rules;
use Ovos\Cache\Versioned\SharedRules;
use Override;
use RedisCluster as RedisClusterClient;
use RedisClusterException;
use RedisException;

use function count;
use function explode;
use function implode;
use function intdiv;
use function is_array;
use function json_encode;
use function microtime;
use function sprintf;
use function usleep;

/**
 * RedisVersioned
 *
 * Logical (rule based) tag invalidation: invalidateTags() appends one
 * rule to a stream instead of deleting the matched items - O(1)
 * regardless of whether 10 or 700000 items match. A read fetches the item
 * with one HMGET and evaluates the rules it has not seen yet against its
 * tags in PHP, over a locally held rule set (see Rules); stale items are
 * lazily unlinked and physically expire by their TTL.
 *
 * The rule set is compacted (the newest rule per tag) and follows the
 * stream incrementally: a refresh, at most once per rules_cache_ms, fetches
 * only the rules appended since the last id held. Where APCu is available
 * the set is shared by the workers of a server (see SharedRules), so a
 * fresh process - every request, under PHP-FPM - adopts it instead of
 * loading the whole stream; "rules_shared_cache: no" switches that off.
 *
 * "rules_retention_s" is the default AND maximum item lifetime of the
 * store: an item must never outlive the rule that made it stale, or it
 * would resurrect once the rule is trimmed. A ttl of 0 means "as long
 * as the store allows" (= the retention); a ttl above the retention is
 * capped to it and logs a warning - raise the retention to at least the
 * longest item TTL in use.
 *
 * Ordering is causal, not clock based: every item is stamped with the
 * rules stream's last entry id its writer holds (its watermark), and only
 * rules with a newer id can invalidate it - no clock comparison happens
 * anywhere (stream ids are generated monotonically by the rules node).
 *
 * The rules stream is the only record that an invalidation happened, so it
 * carries no TTL: under a volatile-* (or noeviction) policy it is then never
 * an eviction candidate - run one of those. Should it be lost anyway (an
 * allkeys-* policy, a DEL, a slot gone with a cluster node), the read path
 * fails safe: an item stamped with a rule the stream no longer remembers is
 * a miss, never a stale hit, at the price of recomputing the group once
 * (see Rules::isStale()).
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
	 * How long a worker holding no rules waits for the worker elected to load
	 * the stream before loading it on its own - the cold start of a server
	 * whose APCu was just emptied, where every request would otherwise load
	 * the whole stream at once
	 * Unit: milliseconds
	 */
	public const int COLD_WAIT_MS = 250;
	
	/**
	 * How often that worker looks whether the load has landed
	 * Unit: milliseconds
	 */
	public const int COLD_POLL_MS = 2;
	
	/**
	 * Invalidation rules (and therefore items) live at most this long.
	 * The default of 30 days sits above the longest TTL in use across
	 * the projects (typical items live ~3 days); note an explicit TTL is
	 * never extended - only a ttl of 0 inherits the retention.
	 * Unit: seconds
	 */
	protected int $rulesRetentionS = 2592000; // 30 days
	
	/**
	 * How long a fetched rule set may be reused before it is refreshed - the
	 * read path evaluates against this held set, and a write takes its
	 * watermark from it - a bounded staleness window. Set to 0 to refresh on every
	 * read (exact, at a round trip each; the refresh is incremental, so it
	 * carries only what was appended since).
	 * Unit: milliseconds
	 */
	protected int $rulesCacheMs = 1000;
	
	/**
	 * Whether the rule set is shared across the workers of a server through
	 * APCu; null decides by availability (see SharedRules::isAvailable())
	 */
	protected ?bool $rulesSharedCache = null;
	
	/**
	 * The rule set this instance holds
	 */
	protected ?Rules $rules = null;
	
	/**
	 * When the held set was last fetched from (or reconciled with) the server
	 */
	protected ?float $rulesFetchedAtMs = null;
	
	/**
	 * This instance appended a rule the held set has not seen yet: the next
	 * read fetches the delta whatever the window says, so a process always
	 * sees its own invalidations at once
	 */
	protected bool $rulesDirty = false;
	
	/**
	 * Stale items a read found, by id: the mark each was read with - dropped
	 * once the read is over, unless a write overwrote them (see afterRead())
	 *
	 * @var array<string, string>
	 */
	protected array $staleDrops = [];
	
	protected ?SharedRules $sharedRules = null;
	
	/**
	 * The server clock minus the local one, in ms (see serverNowMs())
	 */
	protected ?float $clockOffsetMs = null;
	
	/**
	 * The TTL-above-retention warning is logged once per instance
	 */
	protected bool $ttlWarned = false;
	
	#[Override]
	public function setStoreOptions(
		ArrayObject $options,
	): static
	{
		parent::setStoreOptions($options);
		
		if(($retention = $options->offsetGet('rules_retention_s')) !== null)
		{
			$this->rulesRetentionS = (int)$retention;
		}
		if(($cacheMs = $options->offsetGet('rules_cache_ms')) !== null)
		{
			$this->rulesCacheMs = (int)$cacheMs;
		}
		if(($shared = $options->offsetGet('rules_shared_cache')) !== null)
		{
			$this->rulesSharedCache = (bool)$shared;
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
		// a stale item this process read is overwritten here: nothing to drop
		unset($this->staleDrops[$id]);
		// the miss this write follows, if any: refused when the key was
		// deleted (or written through) since, stamped with the watermark the
		// miss saw (see KeyValue::rememberMiss()) - also past the window, where
		// only the watermark still guards
		$miss = $this->takeMiss($id, late: true);
		
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
			// whether the value is soft rides with it: a read judges an
			// invalidated hard value without decoding it (see fetch())
			$soft = $value instanceof Stale && $value->isSoft() ? '1' : '0';
			$value = $this->serializer
				->serialize($value);
			$value = $this->compressor
				->compress($value);
			
			// a miss past the window: its epoch guards nothing any more, but the
			// watermark it took holds for the rules' retention - written as a
			// write-through stamped with it, so an invalidation made while it
			// computed still reaches the value (unstamped: with the watermark held
			// now, as any write-through)
			if($miss !== null && $miss['late'] === true)
			{
				$result = $this->setCall($id, $value, $tags, $ttl, $miss['stamp'] ?? $this->watermark(), $this->writeThroughEpoch(), $soft);
				
				return (int)$result === 1;
			}
			
			// the watermark the miss saw - taken right before the computation
			// (stampMiss()); a miss nobody stamped takes the one held now, which
			// still guards the epoch (a rule between the miss and now is the
			// gap the stamp closes)
			$mark = $miss === null
				? null
				: ($miss['stamp'] ?? $this->missStamp());
			
			$result = $mark === null
				? $this->setCall($id, $value, $tags, $ttl, $this->watermark(), $miss === null ? $this->writeThroughEpoch() : '', $soft)
				: $this->functions
					->call('cache_versioned_set_guarded', [$id], [
						$value,
						implode(',', $tags),
						$ttl * 1000, // ms
						$this->rulesRetentionS * 1000, // ms
						$mark,
						$miss['epoch'],
						$this->invalidationWindowMs,
						$soft,
					]);
			
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
	 * held rule set - see getRules() / isStale() - instead of re-scanning the
	 * rules stream on the server for every read. One round trip; the rules
	 * are refreshed at most once per rules_cache_ms; a stale item is lazily
	 * unlinked.
	 *
	 * This trades exact invalidation for a bounded staleness window
	 * (rules_cache_ms) - set it to 0 to refresh the rules on every read.
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
				static::KEY_EPOCH,
				static::KEY_SOFT,
			]);
			
			// a missing "tags" field means the hash does not exist (an untagged
			// item still stores tags as an empty string) or is a tombstone;
			// "data" must be present too, to decompress
			if(is_array($item) === false
				|| ($item[static::KEY_TAGS] ?? false) === false
				|| ($item[static::KEY_DATA] ?? false) === false)
			{
				$this->rememberMiss($id, is_array($item) ? $item[static::KEY_EPOCH] : false);
				
				return null;
			}
			
			// only the rules newer than the item's mark are evaluated,
			// against the held rule set
			if($this->isStale(
				(string)$item[static::KEY_TAGS],
				(string)$item[static::KEY_MARK],
			))
			{
				// a soft value inside its stale time after the invalidation:
				// handed out aged - served while it is refreshed, a miss to a
				// read without stale: - and left in place, the refresh overwrites
				// it (see softly()); a value its write said is not soft is a hard
				// verdict without decoding it (an item written before the field
				// is decoded to tell)
				if(($item[static::KEY_SOFT] ?? false) !== '0'
					&& ($aged = $this->softly($item)) !== null)
				{
					return $this->found($id, $aged, $item[static::KEY_EPOCH]);
				}
				
				// the stale item is dropped once the read is over - unless a
				// write overwrote it meanwhile, the miss's own recomputation as a
				// rule: no second round trip for the read (see afterRead()). The
				// epoch the item carries stays for the window, so the miss
				// remembers it
				$this->staleDrops[$id] = (string)$item[static::KEY_MARK];
				$this->rememberMiss($id, $item[static::KEY_EPOCH]);
				
				return null;
			}
			
			$value = $this->compressor
				->decompress($item[static::KEY_DATA]);
			
			return $this->found($id,
				$this->serializer->unserialize($value),
				$item[static::KEY_EPOCH],
			);
		}
		catch(RedisException|RedisClusterException $exception)
		{
			$this->log($exception);
		}
		
		return null;
	}
	
	/**
	 * The write that follows no miss (a write-through, a miss past the
	 * guard's window): stamped with $mark, the watermark its writer holds -
	 * never the stream's head, which can hold rules the writer never saw (a
	 * value computed before them would count as having seen them). One key:
	 * cluster-safe
	 */
	protected function setCall(
		string $id,
		string $value,
		array $tags,
		int $ttl,
		string $mark,
		string $epoch = '', // a write-through's: marks the key (see KeyValue::rememberMiss())
		string $soft = '', // '1' a soft value, '0' not (see fetch()); '' says nothing
	): mixed
	{
		return $this->functions
			->call('cache_versioned_set_stamped', [$id], [
				$value,
				implode(',', $tags),
				$ttl * 1000, // ms
				$this->rulesRetentionS * 1000, // ms
				$mark,
				$epoch,
				$this->invalidationWindowMs,
				$soft,
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
			// its tombstone replaces a stale item this process read
			unset($this->staleDrops[$id]);
			
			// its tombstone (see KeyValue\Redis::rememberMiss())
			return $this->tombstone($id) > 0;
		}
		catch(RedisException|RedisClusterException $exception)
		{
			$this->log($exception);
		}
		
		return false;
	}
	
	/**
	 * A miss stamps the rules watermark it saw - the held set's last id (see
	 * watermark(), what every write is stamped with). It lags the stream's
	 * head by at most rules_cache_ms, which only judges the item stale
	 * sooner, never fresh wrongly; and it is consistent with what readers hold:
	 * the stream's head is not - a reader whose held set is momentarily empty
	 * (just after a physical clear) takes any real mark for a lost stream and
	 * reads the item stale. The write that follows is stamped with it
	 * (cache_versioned_set_guarded), so a rule appended since makes the item
	 * stale on its next read. Null when the rules cannot be read: the write
	 * goes unguarded, as before the guard
	 */
	#[Override]
	protected function missStamp(): ?string
	{
		try
		{
			return $this->watermark();
		}
		catch(RedisException|RedisClusterException $exception)
		{
			$this->log($exception);
		}
		
		return null;
	}
	
	/**
	 * The rules stream's last entry id ("<ms>-<seq>", "0-0" when empty) as
	 * this process holds it (rules_cache_ms) - what readers judge an item by,
	 * so a write is stamped with it: a rule appended within the window leaves
	 * it behind, and the item is judged stale sooner - one extra miss, never a
	 * value served past a rule its writer did not hold
	 */
	protected function watermark(): string
	{
		return $this->getRules()
			->last();
	}
	
	/**
	 * Invalidates the items carrying the tags - one rule; $hard: a soft value
	 * it reaches is a miss at once, not served for its stale time - also when a
	 * soft rule follows it, or it follows a soft one (Rules keeps both)
	 */
	public function invalidateTags(
		array $tags,
		string $matching = self::MATCHING_ANY,
		bool $hard = false,
	): bool
	{
		if(count($tags) === 0)
		{
			return false;
		}
		
		return $this->addRule($matching, $tags, $hard);
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
		// the rules stream is wiped too - drop the held rules, here and in the
		// shared cache, so a write that follows is not evaluated against rules
		// that no longer exist
		$this->resetRulesCache();
		
		return parent::clear();
	}
	
	/**
	 * Drops the held rules, and the set shared with the other workers, so the
	 * next read loads the stream afresh - for when the stream itself is gone
	 * (a physical wipe). A rule appended by this instance does not need it:
	 * addRule() marks the set dirty and the next read fetches the delta.
	 */
	protected function resetRulesCache(): void
	{
		$this->rules = null;
		$this->rulesFetchedAtMs = null;
		$this->rulesDirty = false;
		
		// the shared set described a stream that is gone for every worker:
		// drop it whether or not this instance reads it
		if(SharedRules::isAvailable())
		{
			$this->newSharedRules()
				->forget();
		}
	}
	
	/**
	 * Appends one invalidation rule - O(1) regardless of the match count
	 */
	protected function addRule(
		string $mode,
		array $tags,
		bool $hard = false,
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
					$hard ? '1' : '0',
				]);
			
			if($result === false)
			{
				$this->logRefused('cache_versioned_invalidate');
			}
			
			// the held set is behind the rule just written: the next read
			// fetches it (and whatever anyone else appended) in one range
			$this->rulesDirty = true;
			// and so is every set fetched before it on this server: the next
			// request here sees the rule too, not only this one
			$this->getSharedRules()
				?->written(microtime(true) * 1000);
			
			return (int)$result === 1;
		}
		catch(RedisException|RedisClusterException $exception)
		{
			$this->log($exception);
		}
		
		return false;
	}
	
	/**
	 * The rule set, refreshed at most every rules_cache_ms - incrementally,
	 * from the last id it holds, and shared with the other workers of this
	 * server where APCu allows (see SharedRules).
	 *
	 * In order: the held set while it is fresh; the shared set, adopted
	 * unless what is held is newer, and final when it is fresh - no round
	 * trip; otherwise a refresh: one XRANGE from the last id held, absorbed,
	 * stamped and stored for the next worker.
	 *
	 * Where several workers cross the window together, one is elected to
	 * refresh (SharedRules::lead()) and the others keep the set they hold for
	 * this read - one refresh away from exact, never blocked. A worker that
	 * holds nothing, on a server whose APCu was just emptied, waits for the
	 * leader's load for a bounded time (COLD_WAIT_MS) rather than join a herd
	 * of full loads, then loads on its own. Exact reads (rules_cache_ms = 0)
	 * elect nobody: every read refreshes.
	 *
	 * Fails safe: if the rules cannot be (re)loaded it reuses the last-known
	 * set, or throws when none is held - the caller then treats the item as
	 * a miss rather than serving data a rule it could not see might invalidate.
	 */
	protected function getRules(): Rules
	{
		$nowMs = microtime(true) * 1000;
		
		if($this->rules !== null
			&& $this->rulesDirty === false
			&& $this->rulesAreFresh($nowMs))
		{
			return $this->rules;
		}
		
		// not after our own invalidation: the shared set cannot have it yet,
		// and a process must see what it just invalidated
		$shared = $this->rulesDirty
			? null
			: $this->getSharedRules();
		$leading = false;
		
		if($shared !== null)
		{
			if($this->adoptSharedRules($shared)
				&& $this->rulesAreFresh($nowMs))
			{
				return $this->rules;
			}
			
			// behind an invalidation made on this server, the set may lack it
			// and a refresh in flight may have started before it: no election,
			// this read fetches the delta itself
			if($this->rulesCacheMs > 0
				&& $this->behindLocalInvalidation() === false)
			{
				$leading = $shared->lead();
				
				if($leading === false)
				{
					// another worker is refreshing right now: what we hold is
					// one refresh away from exact, good enough for this read
					if($this->rules !== null)
					{
						return $this->rules;
					}
					
					// holding nothing, wait for the leader's load instead
					if($this->awaitSharedRules($shared))
					{
						return $this->rules;
					}
				}
			}
		}
		
		try
		{
			if($this->getClient() === null)
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
			
			$rules = $this->rules ?? new Rules($this->rulesRetentionS * 1000);
			
			try
			{
				$entries = $this->fetchRuleEntries($rules->last());
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
			
			$rules->absorb($entries);
			
			$this->rules = $rules;
			$this->rulesFetchedAtMs = $nowMs;
			$this->rulesDirty = false;
			
			$this->getSharedRules()
				?->store($rules, $nowMs, $rules->wasReset());
			
			return $rules;
		}
		finally
		{
			if($leading)
			{
				$shared->release();
			}
		}
	}
	
	/**
	 * Takes over the set another worker shared, unless what is held is newer.
	 * True when a set was adopted - fresh or not - false when there was
	 * nothing to adopt
	 */
	protected function adoptSharedRules(
		SharedRules $shared,
	): bool
	{
		if(($loaded = $shared->load($this->rulesRetentionS * 1000)) === null)
		{
			return false;
		}
		
		[$rules, $fetchedAtMs] = $loaded;
		
		if($this->rules !== null
			&& Rules::isNewerId($this->rules->last(), $rules->last()))
		{
			return false;
		}
		
		$this->rules = $rules;
		$this->rulesFetchedAtMs = $fetchedAtMs;
		
		return true;
	}
	
	/**
	 * Waits, briefly, for the worker elected to load the stream to share it:
	 * looks every COLD_POLL_MS for at most COLD_WAIT_MS, and gives up early
	 * when the leader has gone without leaving a set. True when a set was
	 * adopted
	 */
	protected function awaitSharedRules(
		SharedRules $shared,
	): bool
	{
		$deadline = microtime(true) + static::COLD_WAIT_MS / 1000;
		
		do
		{
			usleep(static::COLD_POLL_MS * 1000);
			
			// a leader that loaded before an invalidation made on this server
			// shares a set that may lack it: not taken - waited past, or
			// refreshed from once the wait is over
			if($this->adoptSharedRules($shared)
				&& $this->behindLocalInvalidation() === false)
			{
				return true;
			}
		}
		while($shared->isRefreshing() && microtime(true) < $deadline);
		
		return false;
	}
	
	/**
	 * The one call to the server the rule set makes: the entries from $from
	 * (inclusively; the beginning for Rules::NONE) to the head, oldest first
	 *
	 * @return array id => fields
	 */
	protected function fetchRuleEntries(
		string $from,
	): array
	{
		$entries = $this->getClient()
			->xRange(
				$this->getRulesKey(),
				$from === Rules::NONE ? '-' : $from,
				'+',
			);
		
		// anything but a list of entries reads as an empty stream, which the
		// set treats as a loss - a miss, never a stale hit
		return is_array($entries)
			? $entries
			: [];
	}
	
	/**
	 * The read of $id is over: a stale item it found and nothing overwrote
	 * (no resolver, a null result, save: false, a throw, peek()) is dropped
	 * now
	 */
	#[Override]
	protected function afterRead(
		string $id,
	): void
	{
		if(isset($this->staleDrops[$id]) === false)
		{
			return;
		}
		
		$mark = $this->staleDrops[$id];
		unset($this->staleDrops[$id]);
		$this->dropStale($id, $mark);
	}
	
	/**
	 * Removes a stale item - only while it is still that item (Lua
	 * cache_versioned_drop_stale, by the mark the read saw): a delete()
	 * between the read and the removal left a tombstone, and an UNLINK would
	 * erase its epoch; the epoch the item carries stays for the window
	 */
	protected function dropStale(
		string $id,
		string $mark,
	): void
	{
		try
		{
			$this->functions
				->call('cache_versioned_drop_stale', [$id], [
					$mark,
					(string)$this->invalidationWindowMs,
				]);
		}
		catch(RedisException|RedisClusterException $exception)
		{
			$this->log($exception);
		}
	}
	
	/**
	 * Drops what a read left that no afterRead() reached (a manual
	 * lockAndQueue() nothing was written after)
	 */
	public function __destruct()
	{
		foreach($this->staleDrops as $id => $mark)
		{
			$this->dropStale($id, $mark);
		}
		$this->staleDrops = [];
	}
	
	protected function rulesAreFresh(
		float $nowMs,
	): bool
	{
		return $this->rulesFetchedAtMs !== null
			&& $nowMs - $this->rulesFetchedAtMs < $this->rulesCacheMs
			&& $this->behindLocalInvalidation() === false;
	}
	
	/**
	 * Whether the set held was fetched before the latest invalidation a
	 * process of this server made (SharedRules::written()) - it may lack that
	 * rule, so it is never fresh. The fetch time is taken before the stream
	 * is read, so a set fetched after the mark holds the rule. Holding no set
	 * is not behind: there is nothing to serve from (see awaitSharedRules())
	 */
	protected function behindLocalInvalidation(): bool
	{
		if($this->rulesFetchedAtMs === null)
		{
			return false;
		}
		
		$writtenAtMs = $this->getSharedRules()
			?->writtenAtMs();
		
		return $writtenAtMs !== null
			&& $this->rulesFetchedAtMs <= $writtenAtMs;
	}
	
	/**
	 * The set shared with the other workers of this server; null when the
	 * option is off, or APCu is not there to hold it
	 */
	protected function getSharedRules(): ?SharedRules
	{
		if($this->rulesSharedCache === false)
		{
			return null;
		}
		
		if($this->sharedRules === null)
		{
			if(SharedRules::isAvailable() === false)
			{
				$this->rulesSharedCache = false;
				
				return null;
			}
			
			$this->sharedRules = $this->newSharedRules();
		}
		
		return $this->sharedRules;
	}
	
	protected function newSharedRules(): SharedRules
	{
		return new SharedRules(
			$this->getRulesKey(),
			$this->sharedRulesIdentity(),
		);
	}
	
	/**
	 * What tells this store's Redis from another one on the same server -
	 * two environments sharing an FPM pool and a prefix must not share rules
	 */
	protected function sharedRulesIdentity(): string
	{
		$config = $this->connection
			->getConfig();
		
		return (string)json_encode([
			$config->host ?? null,
			$config->port ?? null,
			$config->database ?? null,
			$config->seeds ?? null,
		]);
	}
	
	/**
	 * Soft invalidation: the item a rule invalidated, aged, when it holds a
	 * soft value (get(stale:, soft: true)) and the newest rule that reached it
	 * is younger than the value's stale time - null otherwise (a hard verdict;
	 * also when the rules lost what the item saw, or the server's time cannot
	 * be read). The data is read only here, for an invalidated item
	 *
	 * @param array<string, mixed> $item the HMGET fetch() made
	 */
	protected function softly(
		array $item,
	): ?Stale
	{
		$value = $this->serializer
			->unserialize($this->compressor->decompress($item[static::KEY_DATA]));
		if($value instanceof Stale === false
			|| $value->isSoft() === false)
		{
			return null;
		}
		
		$tags = (string)$item[static::KEY_TAGS];
		$invalidatedAt = $this->getRules()
			->invalidatedAt(
				$tags === '' ? [] : explode(',', $tags),
				(string)$item[static::KEY_MARK],
			);
		if($invalidatedAt === null
			|| ($now = $this->serverNowMs()) === null)
		{
			return null;
		}
		
		return $now - $invalidatedAt < $value->staleFor * 1000
			? $value->invalidated()
			: null;
	}
	
	/**
	 * The server's time in ms - the clock the rules' ids come from, the
	 * stream's own node on a cluster - read once per instance and then kept
	 * as an offset to the local clock (a host and a container drift apart by
	 * seconds; a soft window must not)
	 */
	protected function serverNowMs(): ?int
	{
		if($this->clockOffsetMs === null)
		{
			if(($client = $this->getClient()) === null)
			{
				return null;
			}
			
			try
			{
				$time = $client instanceof RedisClusterClient
					? $client->time($this->getRulesKey())
					: $client->time();
			}
			catch(RedisException|RedisClusterException $exception)
			{
				$this->log($exception);
				
				return null;
			}
			
			if(is_array($time) === false
				|| isset($time[0], $time[1]) === false)
			{
				return null;
			}
			
			$this->clockOffsetMs = (int)$time[0] * 1000 + intdiv((int)$time[1], 1000)
				- microtime(true) * 1000;
		}
		
		return (int)(microtime(true) * 1000 + $this->clockOffsetMs);
	}
	
	/**
	 * Is the item stale under the rules it has not seen? (see Rules::isStale())
	 *
	 * @param string $tags the item's tags, comma separated as stored
	 * @param string $mark the item's watermark
	 */
	protected function isStale(
		string $tags,
		string $mark,
	): bool
	{
		return $this->getRules()
			->isStale(
				$tags === '' ? [] : explode(',', $tags),
				$mark,
			);
	}
}
