<?php
declare(strict_types=1);

namespace Ovos\Cache\Store;

use Closure;
use LengthException;
use Ovos\ArrayObject;
use Ovos\Cache\Stale;
use Ovos\Cache\Store\KeyValue\Redis as Store;
use Ovos\Cache\Versioned\Rules;
use Ovos\Cache\Versioned\SharedRules;
use Override;
use Redis as RedisClient;
use RedisCluster as RedisClusterClient;
use RedisClusterException;
use RedisException;

use function array_keys;
use function array_values;
use function ceil;
use function count;
use function explode;
use function implode;
use function intdiv;
use function is_array;
use function is_string;
use function json_encode;
use function max;
use function microtime;
use function sprintf;

/**
 * RedisVersioned
 *
 * An invalidation does not delete any item. invalidateTags() adds one rule to a list in Redis (a stream),
 * such as "items tagged product:7 written before this rule are outdated". Every PHP worker keeps a copy of
 * that list in memory and checks each item it reads against its copy.
 *
 * So an invalidation costs one rule, whether 10 or 700000 items match. A read is still one HMGET, because
 * the check runs in PHP. An outdated item is a miss. It is removed after that read, or expires by its TTL.
 *
 * Each item stores the id of the newest rule in its writer's copy (the item's "mark" field). Only a rule
 * with a newer id can make the item outdated. The stream gives every rule an id in increasing order, so
 * "newer" is decided by these ids and never by comparing clocks.
 *
 * The copy keeps only the newest rule per tag (see Rules), and a reload fetches only the rules added since
 * the copy's newest one. The PHP workers that share one APCu are called a server here. Where APCu is
 * available, they share one copy (see SharedRules). A new PHP-FPM request then takes that copy instead of
 * loading the whole stream, and one worker reloads it for all of them about once per rules_cache_ms.
 * "rules_shared_cache: no" turns the sharing off. readRules() decides which copy checks a read.
 *
 * An example, with rules_cache_ms 1000. At 12:00:00.000 worker A on server web1 invalidates the tag
 * product:7. Redis's reply names the rule that came before the new one. A's copy ends with that rule, so A
 * adds the new rule to its copy and puts the copy in APCu for the other workers of web1 (see addRule()).
 * A's next read of an item tagged product:7 is a miss, and the value is computed again. Worker B on server
 * web2 fetched its copy at 11:59:59.600. B still serves the old product:7 item until 12:00:00.600, because
 * until then its copy is less than rules_cache_ms old. B's first read after that gets a reloaded copy,
 * which holds the rule, so the item is a miss for B too.
 *
 * When something goes wrong:
 * - Redis does not answer when the rules are reloaded. The workers keep using the copy they have, whatever
 *   its age. A worker with no copy treats every item as a miss and stores nothing it computes. One worker
 *   of the server tries again RETRY_MS after the last attempt ended.
 * - Another worker is reloading. A copy at most rules_cache_ms + SLOW_RELOAD_MS old is still used. With an
 *   older copy, or none, every item is a miss, computed without the stampede lock, so nobody waits. The
 *   value is stored with the older copy's newest rule id, and not stored when there is no copy. A reload
 *   that runs longer than SLOW_RELOAD_MS is treated as if Redis were down.
 * - A write races an invalidation. The value is stored with the newest rule id of the copy its read was
 *   checked against, never with the stream's newest id. So a rule added while the value was computed
 *   still reaches it on the next read.
 *
 * More in README.CACHE.md: "RedisVersioned" under "Store differences" (the parts "The rule set while it
 * reloads" and "An invalidation made on this server"), and "Invalidation guard (stale writes)".
 *
 * "rules_retention_s" is the default and the maximum lifetime of an item. An item must never live longer
 * than the rule that made it outdated, or it would be served again once that rule is trimmed from the
 * stream. A ttl of 0 means "as long as the store allows", which is the retention. A ttl above the
 * retention is cut to it and logs a warning, so set the retention to at least the longest TTL in use.
 *
 * The rules stream is the only record that an invalidation happened, so it has no TTL. Under a volatile-*
 * (or noeviction) policy Redis then never evicts it, so run one of those. If the stream is lost anyway (an
 * allkeys-* policy, a DEL, a slot lost with a cluster node), reads stay safe. An item that carries a rule
 * id the stream no longer holds is a miss, never an outdated hit, and the group is computed again once
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
	
	/**
	 * Recorded as a miss's rule id when no copy of the invalidation rules was at hand, either for its read
	 * or when its rule id was taken. Then nothing can tell whether the value computed after it is already
	 * outdated. That value goes back to the caller and is never stored (see KeyValue\Redis::refuseUnjudged()).
	 * No stream id looks like this.
	 */
	public const string STAMP_UNREAD = 'unread';
	
	// Types
	public const string TYPE_RULES = 'rules';
	
	/**
	 * While another worker reloads the rules, the others keep using their copy for up to this long past
	 * $rulesCacheMs. A reload that runs longer than this is treated as if Redis were down (see readRules()).
	 * Unit: milliseconds
	 */
	public const int SLOW_RELOAD_MS = 1000;
	
	/**
	 * While the rules cannot be read, one worker of the server tries again once this long has passed since
	 * the last attempt ended.
	 * Unit: milliseconds
	 */
	public const int RETRY_MS = 1000;
	
	/**
	 * Invalidation rules, and so items, live at most this long. The default of 30 days is above the longest
	 * TTL used in the projects (items typically live about 3 days). An explicit TTL is never made longer;
	 * only a ttl of 0 takes the retention.
	 * Unit: seconds
	 */
	protected int $rulesRetentionS = 2592000; // 30 days
	
	/**
	 * How long a copy of the rules is used for reads before it is reloaded. So an invalidation made on
	 * another server can go unseen for about this long. 0 reloads before every read ("exact" reads), at one
	 * round trip each. A reload fetches only the rules added since, so it stays small.
	 * Unit: milliseconds
	 */
	protected int $rulesCacheMs = 1000;
	
	/**
	 * Whether the workers of a server share one copy of the rules through APCu. Null decides by whether
	 * APCu is available (see SharedRules::isAvailable()).
	 */
	protected ?bool $rulesSharedCache = null;
	
	/**
	 * How many rules the copy keeps. Past that, a floor takes the place of the oldest rules (see Rules).
	 * Store option "rules_max_tags"; 0 means no limit.
	 */
	protected int $rulesMaxTags = Rules::MAX_TAGS;
	
	/**
	 * How many stream entries one fetch reads. A load from the start of the stream goes batch by batch, so
	 * at most one batch is in memory beside the copy.
	 */
	protected int $rulesBatch = 10000;
	
	/**
	 * This instance's copy of the invalidation rules; null when it has none
	 */
	protected ?Rules $rules = null;
	
	/**
	 * When the fetch that read the copy began (ms). The copy's age counts from there, not from when the
	 * fetch ended.
	 */
	protected ?float $rulesFetchedAtMs = null;
	
	/**
	 * The newest invalidation this instance made: when (ms, this server's clock, taken after Redis added
	 * the rule) and the rule id it got. Kept here too for a store that shares no copy; SharedRules keeps
	 * the same record for the whole server.
	 */
	protected ?float $writtenAtMs = null;
	
	protected string $writtenId = Rules::NONE;
	
	/**
	 * Whether this instance already shared, in an invalidation call, a copy its own rule went into (see
	 * addRule())
	 */
	protected bool $sharedInRequest = false;
	
	/**
	 * The copy holds rules this instance added that the shared copy lacks. It is shared when the instance
	 * goes away, so a loop of invalidations writes the copy to APCu twice, not once per call.
	 */
	protected bool $unshared = false;
	
	/**
	 * Used when no copy is shared on this server: whether the last attempt to read the rules stream
	 * failed, and when it ended. With a shared copy, SharedRules keeps this for all workers.
	 */
	protected bool $outage = false;
	
	protected ?float $attemptEndedAtMs = null;
	
	/**
	 * APCu refused to record the reload flag the last time this worker tried to take it. Until APCu takes
	 * it, this worker loads the rules on its own and shares nothing.
	 */
	protected bool $alone = false;
	
	protected bool $aloneWarned = false;
	
	protected bool $storeWarned = false;
	
	/**
	 * Outdated items a read found, by id, each with the "mark" its read saw. They are removed once the read
	 * is over, unless a write replaced them meanwhile (see afterRead()).
	 *
	 * @var array<string, string>
	 */
	protected array $staleDrops = [];
	
	protected ?SharedRules $sharedRules = null;
	
	/**
	 * The Redis server's clock minus the local clock, in ms (see serverNowMs())
	 */
	protected ?float $clockOffsetMs = null;
	
	/**
	 * The rule id for writes that have none of their own: a set() with no miss before it, or a miss older
	 * than the guard window that never got one. Taken once per set() or batch (see writeStamp()).
	 * STAMP_UNREAD when no copy of the rules was at hand.
	 */
	protected ?string $writeStamp = null;
	
	/**
	 * The TTL-above-retention warning is logged once per instance
	 */
	protected bool $ttlWarned = false;
	
	#[Override]
	public function setGroup(
		?string $group,
	): static
	{
		if($this->group !== $group)
		{
			// The copy belongs to the old group. If it holds rules this instance added and has not
			// shared yet, it is shared with the old group's workers first.
			$this->shareUnshared();
			parent::setGroup($group);
			$this->resetLocalRules();
			$this->misses = [];
			$this->staleDrops = [];
		}
		
		return $this;
	}
	
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
		if(($maxTags = $options->offsetGet('rules_max_tags')) !== null)
		{
			$this->rulesMaxTags = (int)$maxTags;
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
		// The miss this write follows, if any. The write is refused when the key was deleted or written
		// by someone else since that miss (see KeyValue::rememberMiss()). The value is stored with the
		// rule id the miss saw. The miss is taken even when it is older than the guard window, because
		// its rule id still protects the write then.
		$miss = $this->writeMiss($id);
		// a write without a rule id of its own takes a new one for this call (see writeStamp())
		$this->writeStamp = null;
		
		// A negative TTL would expire the item at once, so it is a delete. So is a null value (see
		// KeyValue::set()).
		if($ttl < 0 || $value === null)
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
		
		// not stored when nothing could check it: the item read failed, or no copy of
		// the invalidation rules was at hand for the read. A write that only fills a
		// miss leaves the key alone; a write meant to replace the value deletes it, so
		// the old one is not served (see refuseUnjudged())
		if($this->isUnread($miss)
			|| $this->isUnjudged($miss))
		{
			return $this->refuseUnjudged($key, $id, $miss);
		}
		
		// One Lua call writes the value with its rule id. After a miss it is refused when the key
		// changed since. The stampede lock is released in the same round trip (see writeWithRelease()).
		try
		{
			$result = $this->writeWithRelease($id, ...$this->writeCall($id, $key, $value, $ttl, $tags, $miss));
			
			return (int)$result === 1;
		}
		catch(RedisException|RedisClusterException $exception)
		{
			$this->log($exception);
		}
		// The value is longer than the Compressor reads back, so it is not cached (see dropRefused()).
		catch(LengthException $exception)
		{
			$this->log($exception->getMessage());
			$this->dropRefused($key, $miss);
		}
		// the lock is released whatever happened; a second release does nothing
		finally
		{
			$this->getMemoLock()
				->debug('save: ' . $id)
				->releaseActiveLock($id);
		}
		
		return false;
	}
	
	/**
	 * Reads an item with one HMGET (data, tags, mark, epoch, soft) and checks it in PHP against the copy of
	 * the rules that readRules() picks. Only rules newer than the item's "mark" are checked. This avoids
	 * scanning the rules stream in Redis for every read. One round trip; an outdated item is removed after
	 * the read.
	 *
	 * The price: an invalidation made on another server can go unseen for up to rules_cache_ms (plus
	 * SLOW_RELOAD_MS while another worker reloads). Set rules_cache_ms to 0 to reload the rules before
	 * every read.
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
			// One HMGET brings the data, the tags and the "mark". The data is read outside any Lua script,
			// and only decompressed when the item turns out to be fresh.
			$item = $client->hMGet($id, $this->fetchedFields());
		}
		// Redis did not answer: a miss, and no write may follow it (see rememberUnread())
		catch(RedisException|RedisClusterException $exception)
		{
			$this->log($exception);
			$this->rememberUnread($id);
			
			return null;
		}
		
		// an error reply (BUSY, LOADING) is a failed read too (see failedReply())
		if(is_array($item) === false)
		{
			$this->failedReply($id);
			
			return null;
		}
		
		return $this->judge($id, $item);
	}
	
	/**
	 * The fields fetch() reads with its HMGET
	 *
	 * @return list<string>
	 */
	protected function fetchedFields(): array
	{
		return [
			static::KEY_DATA,
			static::KEY_TAGS,
			static::KEY_MARK,
			static::KEY_EPOCH,
			static::KEY_SOFT,
		];
	}
	
	/**
	 * Checks what fetch()'s HMGET read ($item: the reply) against the copy of the rules that readRules()
	 * picks. Also used for the second read made together with the stampede lock (see lockAndLook()).
	 */
	protected function judge(
		string $id,
		mixed $item,
	): mixed
	{
		[$rules, $unlocked] = $this->readRules();
		
		return $this->judgeBy($id, $item, $rules, $unlocked);
	}
	
	/**
	 * Checks $item against the copy $rules and returns its value, or null for a miss. With no copy ($rules
	 * null) every item is a miss. With $unlocked the copy may not be used for this read (see readRules()):
	 * every item is a miss, and its value is computed without the stampede lock (see
	 * KeyValue::$unlockedMisses).
	 */
	protected function judgeBy(
		string $id,
		mixed $item,
		?Rules $rules,
		bool $unlocked,
	): mixed
	{
		// get() and getMany() look here to know whether this miss skips the stampede lock
		if($unlocked)
		{
			$this->unlockedMisses[$id] = true;
		}
		else
		{
			unset($this->unlockedMisses[$id]);
		}
		
		// the guard's token on the key (see KeyValue::rememberMiss()); false when there is none
		$epoch = is_array($item)
			? ($item[static::KEY_EPOCH] ?? false)
			: false;
		
		// No copy of the rules, so nothing can tell whether the item is outdated. It is a miss, the item
		// stays in Redis, and the value computed after it is not stored (see isUnjudged()).
		if($rules === null)
		{
			$this->rememberMiss($id, $epoch);
			$this->unjudged($id);
			
			return null;
		}
		
		// a copy is at hand: check the item against it
		$found = $this->verdict($id, $item, $rules, $unlocked, $epoch);
		
		// A miss records the copy's newest rule id now, and the value computed after it is stored with that
		// id. The copy may lack the stream's latest rules, which only makes the item outdated sooner. So the
		// computation does not need to go through readRules() again.
		if(isset($this->misses[$id])
			&& $this->misses[$id]['stamp'] === null)
		{
			$this->misses[$id]['stamp'] = $rules->last();
		}
		
		return $found;
	}
	
	/**
	 * judgeBy()'s check of $item when a copy of the rules is at hand: the value, or null for a miss
	 */
	protected function verdict(
		string $id,
		mixed $item,
		Rules $rules,
		bool $unlocked,
		mixed $epoch,
	): mixed
	{
		// The copy may not be used for this read (another worker is reloading, see readRules()). So it is
		// a miss, and the write after it is still guarded by the epoch.
		if($unlocked)
		{
			$this->rememberMiss($id, $epoch);
			
			return null;
		}
		
		// A missing "tags" field means the hash does not exist (an untagged item still stores tags as an
		// empty string), or it is a tombstone that holds only an epoch. "data" must be there too, to
		// decompress.
		if(is_array($item) === false
			|| ($item[static::KEY_TAGS] ?? false) === false
			|| ($item[static::KEY_DATA] ?? false) === false)
		{
			$this->rememberMiss($id, $epoch);
			
			return null;
		}
		
		// only the rules newer than the item's "mark" are checked against its tags
		$tags = (string)$item[static::KEY_TAGS];
		if($rules->isStale($tags === '' ? [] : explode(',', $tags), (string)$item[static::KEY_MARK]))
		{
			// The item is outdated. A soft value still inside its stale time comes back aged: a read with
			// stale: serves it while it is refreshed, a read without stale: takes it for a miss. It stays
			// in Redis until the refresh overwrites it (see softly()). An item whose write said it is not
			// soft (soft field '0') is outdated without decoding it. An item written before that field
			// existed is decoded to find out.
			$aged = ($item[static::KEY_SOFT] ?? false) !== '0'
				? $this->softly($item, $rules)
				: null;
			if($aged instanceof Stale)
			{
				return $this->found($id, $aged, $epoch);
			}
			
			// The Redis server's time could not be read, so we cannot tell whether the soft value is
			// still inside its stale time. It is a miss for this read only, and the item stays for the
			// readers that can read the time.
			if($aged === false)
			{
				$this->rememberMiss($id, $epoch);
				
				return null;
			}
			
			// The item is outdated: a miss. It is removed once the read is over, unless a write replaced
			// it meanwhile, which is usually this miss's own computation. So the read needs no second
			// round trip (see afterRead()). The removal keeps the item's epoch for the guard window, so
			// the miss remembers that epoch.
			$this->staleDrops[$id] = (string)$item[static::KEY_MARK];
			$this->rememberMiss($id, $epoch);
			
			return null;
		}
		
		// fresh: only now is the data decompressed
		$value = $this->compressor
			->decompress($item[static::KEY_DATA]);
		
		return $this->found($id,
			$this->serializer->unserialize($value),
			$epoch,
		);
	}
	
	/**
	 * The write for a value that no miss guards: a set() with no miss before it, or a miss older than the
	 * guard window. It is stored with $mark, the newest rule id of the writer's copy. Never with the
	 * stream's newest id: the stream may hold rules the writer never saw, and a value computed before them
	 * would then count as computed after them. One key, so it works on a cluster.
	 */
	protected function stampedCall(
		string $id,
		string $value,
		array $tags,
		int $ttl,
		string $mark,
		string $epoch = '', // the key's new epoch, for a write with no miss (see KeyValue::rememberMiss())
		string $soft = '', // '1' a soft value, '0' not (see verdict()); '' says nothing
	): array
	{
		return ['cache_versioned_set_stamped', [$id], [
			$value,
			implode(',', $tags),
			$ttl * 1000, // ms
			$this->rulesRetentionS * 1000, // ms
			$mark,
			$epoch,
			$this->invalidationWindowMs,
			$soft,
		]];
	}
	
	/**
	 * The miss a write of $id follows, also when it is older than the guard window (see set()). A miss
	 * that never got a rule id takes the newest rule id of the copy now. That happens when no computation
	 * ran under stampMiss(), as with peek() and then set(). The epoch still guards that write against a
	 * delete. Only a rule id taken before the computation would catch a rule added between the miss and
	 * the write.
	 */
	#[Override]
	protected function writeMiss(
		string $id,
	): ?array
	{
		// the write replaces any outdated item this process read, so there is nothing left to remove
		unset($this->staleDrops[$id]);
		
		$miss = $this->takeMiss($id, late: true);
		if($miss !== null
			&& $miss['late'] === false
			&& $miss['stamp'] === null)
		{
			$miss['stamp'] = $this->missStamp();
		}
		
		return $miss;
	}
	
	/**
	 * Whether the read of $miss failed (see rememberUnread()). Here this holds past the guard window too.
	 * The write after it is refused once, and then the miss is gone, because writeMiss() took it.
	 */
	#[Override]
	protected function isUnread(
		?array $miss,
	): bool
	{
		return $miss !== null
			&& $miss['epoch'] === static::EPOCH_UNREAD;
	}
	
	/**
	 * Whether the value written after $miss cannot be checked: no copy of the rules was at hand for its
	 * read, or when its rule id was taken (see STAMP_UNREAD). This holds past the guard window too.
	 */
	#[Override]
	protected function isUnjudged(
		?array $miss,
	): bool
	{
		// a write without a rule id of its own (no miss, or a late miss that never got one): whether
		// writeStamp() found a copy of the rules
		if($miss === null
			|| $miss['stamp'] === null)
		{
			return $this->writeStamp() === static::STAMP_UNREAD;
		}
		
		return $miss['stamp'] === static::STAMP_UNREAD;
	}
	
	/**
	 * Handles the key of a write that was not made, because the value is longer than the Compressor reads
	 * back. If the store computed the value itself after its miss, the key stays, since it may hold a valid
	 * item. Any other write deletes the key, so the value it was meant to replace is not served.
	 */
	#[Override]
	protected function dropRefused(
		string $key,
		?array $miss,
	): void
	{
		if(($miss['computing'] ?? false) === false)
		{
			$this->delete($key);
		}
	}
	
	/**
	 * The rule id for a write with no rule id of its own, taken from the copy a read would use now (see
	 * missStamp()). Taken once per set() or batch. STAMP_UNREAD when there is no copy.
	 */
	protected function writeStamp(): string
	{
		return $this->writeStamp ??= $this->missStamp();
	}
	
	/**
	 * One rule id for all the writes of a batch that have none of their own (see writeStamp())
	 */
	#[Override]
	protected function storeMany(
		array $entries,
	): array
	{
		// a new batch takes a new rule id
		$this->writeStamp = null;
		
		return parent::storeMany($entries);
	}
	
	/**
	 * Records that the miss of $id could not be checked, because no copy of the rules was at hand.
	 * stampMiss() then keeps STAMP_UNREAD as its rule id, so nothing is stored after it.
	 */
	protected function unjudged(
		string $id,
	): void
	{
		if(isset($this->misses[$id]))
		{
			$this->misses[$id]['stamp'] = static::STAMP_UNREAD;
		}
	}
	
	/**
	 * The write set() sends (see KeyValue\Redis::writeCall()). After a miss it is
	 * cache_versioned_set_guarded: refused when the key's epoch changed, and stored with the rule id the
	 * miss saw. With no miss, or one older than the guard window, it is cache_versioned_set_stamped, stored
	 * with the newest rule id of the writer's copy.
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
		// A TTL above the retention is cut to it (see the class doc). That is logged once, so the shorter
		// life is no silent surprise.
		if($ttl > $this->rulesRetentionS && $this->ttlWarned === false)
		{
			$this->ttlWarned = true;
			$this->log(sprintf(
				'Cache TTL %d s exceeds rules_retention_s %d s and is capped to it - raise the retention to keep longer-lived items.',
				$ttl,
				$this->rulesRetentionS,
			));
		}
		
		// Whether the value is soft is stored beside it. Then a read takes an outdated hard value for a
		// miss without decoding it (see verdict()).
		$soft = $value instanceof Stale && $value->isSoft() ? '1' : '0';
		$value = $this->serializer
			->serialize($value);
		$value = $this->compressor
			->compress($value);
		
		// no miss before it: stored with the copy's newest rule id, and a new epoch for the key
		if($miss === null)
		{
			return $this->stampedCall($id, $value, $tags, $ttl, $this->writeStamp(), $this->writeThroughEpoch(), $soft);
		}
		
		// A miss older than the guard window. Its epoch protects nothing any more, but its rule id stays
		// valid for the rules' retention. So the value is written like a write with no miss, but with that
		// rule id, and a rule added while it was computed still reaches it. A late miss without a rule id
		// takes the copy's newest one, like any write with no miss.
		if($miss['late'] === true)
		{
			return $this->stampedCall($id, $value, $tags, $ttl, $miss['stamp'] ?? $this->writeStamp(), $this->writeThroughEpoch(), $soft);
		}
		
		// The guarded write, with the rule id the miss saw. That id was taken right before the computation
		// (stampMiss()), or by writeMiss() for a miss that never got one.
		return ['cache_versioned_set_guarded', [$id], [
			$value,
			implode(',', $tags),
			$ttl * 1000, // ms
			$this->rulesRetentionS * 1000, // ms
			$miss['stamp'],
			$miss['epoch'],
			$this->invalidationWindowMs,
			$soft,
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
			// the delete replaces any outdated item this process read, so there is nothing left to remove
			unset($this->staleDrops[$id]);
			
			// The key keeps only a new epoch for the guard window, so a value computed before this delete
			// is refused (see KeyValue\Redis::tombstone()).
			return $this->tombstone($id) > 0;
		}
		catch(RedisException|RedisClusterException $exception)
		{
			$this->log($exception);
		}
		
		return false;
	}
	
	/**
	 * Takes the stampede lock and reads the item a second time in one round trip, on a standalone client
	 * (see MemoLock::lockAndQueue()). SET NX and fetch()'s HMGET go in one pipeline, and the second read is
	 * checked as fetch() checks it. On a cluster: null, because the lock and the item are in different
	 * slots.
	 */
	#[Override]
	protected function lockAndLook(
		string $id,
	): ?Closure
	{
		if(($client = $this->getClient()) instanceof RedisClient === false)
		{
			return null;
		}
		
		return function(string $lockKey, string $lockValue, int $ttlMs) use ($client, $id): array
		{
			$replies = $client->pipeline()
				->set($lockKey, $lockValue, ['NX', 'PX' => $ttlMs])
				->hMGet($id, $this->fetchedFields())
				->exec();
			if(($replies[0] ?? false) !== true)
			{
				return [false, null];
			}
			
			// The lock is taken. A second read that failed, or one with no copy of the rules at hand, is
			// a miss, as in fetch() (see judge()). So MemoLock records the lock, and the write releases it.
			$look = $replies[1] ?? false;
			if(is_array($look) === false)
			{
				$this->failedReply($id);
				
				return [true, null];
			}
			
			return [true, $this->fresh($this->judge($id, $look))];
		};
	}
	
	/**
	 * Takes the stampede locks of a batch and reads each item a second time in one round trip, on a
	 * standalone client (see MemoLock::lockMany()). SET NX and fetch()'s HMGET per key go in one pipeline,
	 * and the second reads are checked as fetch() checks them, against one copy of the rules for the whole
	 * batch. On a cluster: null, because the locks and the items are in different slots.
	 */
	#[Override]
	protected function lockAndLookMany(): ?Closure
	{
		if(($client = $this->getClient()) instanceof RedisClient === false)
		{
			return null;
		}
		
		return function(array $lockKeys, array $lockValues, int $ttlMs) use ($client): array
		{
			$pipeline = $client->pipeline();
			foreach($lockKeys as $id => $lockKey)
			{
				$pipeline->set($lockKey, $lockValues[$id], ['NX', 'PX' => $ttlMs]);
			}
			foreach($lockKeys as $id => $lockKey)
			{
				$pipeline->hMGet($id, $this->fetchedFields());
			}
			$replies = $pipeline->exec();
			$count = count($lockKeys);
			
			$taken = [];
			$looked = [];
			$index = 0;
			foreach($lockKeys as $id => $lockKey)
			{
				$taken[$id] = [($replies[$index] ?? false) === true, null];
				$look = $replies[$count + $index] ?? false;
				$index++;
				if($taken[$id][0] === false)
				{
					continue;
				}
				
				// a second read that failed is a miss, with nothing to check
				if(is_array($look) === false)
				{
					$this->failedReply($id);
					
					continue;
				}
				
				$looked[$id] = $look;
			}
			
			// The locks are taken. A second read with no copy of the rules at hand is a miss, as in
			// fetch() (see judgeMany()). So MemoLock records the locks, and the writes release them.
			$looks = $this->judgeMany(array_keys($looked), array_values($looked));
			foreach($looked as $id => $reply)
			{
				$taken[$id][1] = $this->fresh($looks[$id] ?? null);
			}
			
			return $taken;
		};
	}
	
	/**
	 * Checks a batch's replies against ONE copy of the rules, picked once before the first. A batch counts
	 * as one read, whatever rules_cache_ms says.
	 */
	#[Override]
	protected function judgeMany(
		array $ids,
		array $replies,
	): array
	{
		// nothing to check, so no copy of the rules is needed (a worker without one does not load it)
		if($ids === [])
		{
			return [];
		}
		
		[$rules, $unlocked] = $this->readRules();
		
		$found = [];
		foreach($ids as $index => $id)
		{
			$found[$id] = $this->judgeBy($id, $replies[$index] ?? false, $rules, $unlocked);
		}
		
		return $found;
	}
	
	/**
	 * Removes the outdated items a batch's reads found (see afterRead()). On a standalone client all the
	 * removals go in one round trip; otherwise, or if that fails, one by one.
	 */
	#[Override]
	protected function afterReadMany(
		array $ids,
	): void
	{
		$drops = [];
		foreach($ids as $id)
		{
			if(isset($this->staleDrops[$id]))
			{
				$drops[$id] = $this->staleDrops[$id];
				unset($this->staleDrops[$id]);
			}
		}
		if($drops === [])
		{
			return;
		}
		
		$calls = [];
		foreach($drops as $id => $mark)
		{
			$calls[] = [
				$this->functions->functionsPrefix('cache_versioned_drop_stale'),
				[$id],
				[$mark, (string)$this->invalidationWindowMs],
			];
		}
		try
		{
			if($this->sendAll($calls) !== null)
			{
				return;
			}
		}
		catch(RedisException|RedisClusterException $exception)
		{
			$this->log($exception);
		}
		
		foreach($drops as $id => $mark)
		{
			$this->dropStale($id, $mark);
		}
	}
	
	/**
	 * The rule id a miss records: the newest rule id of the copy a read would use now (readRules(), which
	 * may make this worker the one that reloads). Never the stream's newest id. The copy may lack rules the
	 * stream already has, which only makes the item outdated sooner, never fresh by mistake. The stream's
	 * newest id would also not match what readers hold: a reader whose copy is empty for a moment (just
	 * after a physical clear) takes any real rule id on an item as a sign of a lost stream, and reads the
	 * item as outdated. The write that follows stores this id (cache_versioned_set_guarded), so a rule added
	 * since makes the item outdated on its next read. STAMP_UNREAD when there is no copy: nothing is stored
	 * after the miss (see isUnjudged()).
	 */
	#[Override]
	protected function missStamp(): string
	{
		[$rules] = $this->readRules();
		
		return $rules?->last() ?? static::STAMP_UNREAD;
	}
	
	/**
	 * Invalidates the items carrying the tags by adding one rule. With $hard, a soft value the rule reaches
	 * is a miss at once instead of being served for its stale time. That also holds when a soft rule
	 * follows the hard one, or the hard one follows a soft one, because Rules keeps both.
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
	 * Clears logically: one "all" rule with no tags makes every item outdated. The items stay in Redis
	 * until their TTL (see clearPhysical()).
	 */
	#[Override]
	public function clear(): bool
	{
		return $this->addRule(static::MATCHING_ALL, []);
	}
	
	/**
	 * Deletes the whole group, items and rules. Normally not needed: clear() is logical, and items expire
	 * by their TTL.
	 */
	public function clearPhysical(): bool|int
	{
		// The rules stream is deleted too. So the copy is dropped, here and in APCu, and a write that
		// follows is not checked against rules that no longer exist.
		$this->resetRulesCache();
		
		return parent::clear();
	}
	
	/**
	 * Drops this instance's copy and the shared copy, so the next read loads the stream from the start. For
	 * when the stream itself is gone (a physical wipe).
	 */
	protected function resetRulesCache(): void
	{
		$this->resetLocalRules();
		
		// The shared copy describes a stream that is gone for every worker. So it is dropped even when
		// this instance does not use it.
		if($this->sharesRules())
		{
			$this->newSharedRules()
				->forget();
		}
	}
	
	/**
	 * Forgets what this instance knows about the rules: its copy, its own invalidations and the reload
	 * state
	 */
	protected function resetLocalRules(): void
	{
		$this->rules = null;
		$this->rulesFetchedAtMs = null;
		$this->writtenAtMs = null;
		$this->writtenId = Rules::NONE;
		$this->sharedInRequest = false;
		$this->unshared = false;
		$this->outage = false;
		$this->attemptEndedAtMs = null;
		$this->alone = false;
		$this->sharedRules = null;
		$this->clockOffsetMs = null;
	}
	
	/**
	 * Adds one invalidation rule to the stream: O(1), whatever the number of matching items.
	 *
	 * Redis's reply names the rule that came just before the new one. If this instance's copy ends with
	 * that rule, the copy holds everything up to the new rule. Then the new rule goes into the copy
	 * straight from the reply, with no fetch, and the copy counts as fetched right before the call.
	 *
	 * The first time this happens in an instance, the copy is shared at once. That happens before the
	 * server's record of its newest invalidation is updated (see SharedRules::written()). So a worker that
	 * sees that record finds a shared copy that already holds the rule, and takes it with no fetch and no
	 * miss. Later invalidations of the same instance are shared once, when the instance goes away (see
	 * shareUnshared()). Otherwise a loop of invalidations would write the whole copy to APCu on every call.
	 *
	 * If the copy ends anywhere else, another rule was added in between, and adding only the new rule would
	 * skip that one. Then the copy is left as it is. Like every copy on this server fetched before now, it
	 * may lack the new rule, so it checks no read until it is reloaded (unless the rules cannot be read,
	 * see readRules()).
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
		
		// Taken before the call. Any rule added before this moment comes at or before the rule the new one
		// follows. So a copy that ends with that rule holds everything added until now.
		$beforeMs = microtime(true) * 1000;
		try
		{
			// One FCALL on the rules key, so it works on a cluster. The stream gives the new rule its id,
			// always higher than the ids before it.
			$result = $this->functions
				->call('cache_versioned_invalidate', [$this->getRulesKey()], [
					$mode,
					implode(',', $tags),
					$this->rulesRetentionS * 1000, // ms
					$hard ? '1' : '0',
				]);
		}
		// Redis did not answer: logged, and the invalidation reports failure
		catch(RedisException|RedisClusterException $exception)
		{
			$this->log($exception);
			
			return false;
		}
		
		// The previous release's Lua library answers 1 and names no ids. A host may still call it for up
		// to Functions::CONFIRMED_TTL_S after a deploy. The rule is in the stream. The copy loses its fetch
		// time, so it no longer counts as fresh, and the next read loads a copy first (see readRules()).
		if($result === 1)
		{
			$this->rulesFetchedAtMs = null;
			
			return true;
		}
		
		// any other answer than the new id: the call was refused
		if(is_array($result) === false
			|| is_string($id = $result[0] ?? null) === false)
		{
			$this->logRefused('cache_versioned_invalidate');
			
			return false;
		}
		
		// the id of the rule just before the new one ('0-0' when the stream was empty)
		$previous = $result[1] ?? null;
		// Taken after the reply. A copy fetched before this moment may lack the new rule.
		$atMs = microtime(true) * 1000;
		
		// The copy ends with the rule just before the new one, so it holds everything up to the new rule.
		// The new rule goes into it from the reply.
		if($this->rules !== null
			&& $previous === $this->rules->last())
		{
			// the rule's fields, as the stream stores them
			$fields = [
				Rules::FIELD_MODE => $mode,
				Rules::FIELD_TAGS => implode(',', $tags),
				Rules::FIELD_FIRST => ($result[2] ?? null) === '1' ? '1' : '0',
			];
			if($hard)
			{
				$fields[Rules::FIELD_HARD] = '1';
			}
			
			// The rule is added the way a fetch from the copy's newest id would bring it: that id first
			// (unless the stream was empty), then the new rule. The copy counts as fetched right before the
			// call, so other workers see it exactly as fresh as it is.
			$this->rules->absorb($previous === Rules::NONE
				? [$id => $fields]
				: [$previous => [], $id => $fields]);
			$this->rulesFetchedAtMs = $beforeMs;
			
			// The first invalidation of this instance shares the copy at once. Later ones only note that
			// the copy is not shared yet; it is shared when the instance goes away.
			if($this->sharedInRequest)
			{
				$this->unshared = true;
			}
			else
			{
				$this->sharedInRequest = true;
				$this->shareHeldRules();
			}
		}
		
		// Record the newest invalidation, here and for every worker of this server. A copy fetched before
		// now that does not hold this rule is then not used until it is reloaded (see
		// behindLocalInvalidation()).
		$this->writtenAtMs = $atMs;
		$this->writtenId = $id;
		$this->getSharedRules()
			?->written($atMs, $id);
		
		return true;
	}
	
	/**
	 * Picks the copy of the rules that checks a read, and returns [copy, unlocked]. The copy is null when
	 * there is none. With unlocked false, the copy checks the read. With unlocked true, every item read is a
	 * miss. Its value is then stored with the copy's newest rule id (see missStamp()), or not stored at all
	 * when there is no copy (see isUnjudged()). It is computed without the stampede lock: a worker waiting
	 * for the lock would read a miss as well, so the lock would only make the workers queue.
	 *
	 * The first case that applies decides:
	 *
	 * 1. The copy was fetched less than rules_cache_ms ago, by this worker or by another worker of the
	 *    server that shared it, and it holds every invalidation made on this server. It checks the read,
	 *    with no round trip. With exact reads (rules_cache_ms 0) this case never applies.
	 * 2. The rules cannot be read (the last attempt failed: an outage), or another worker's reload has run
	 *    longer than SLOW_RELOAD_MS. The copy checks the read whatever its age, even if it lacks an
	 *    invalidation made on this server. With no copy, every item is a miss. One worker tries the stream
	 *    again once the reload flag is free and RETRY_MS has passed since the last attempt ended.
	 * 3. The reload flag is free. This worker takes it and reloads the copy for the server (see reload()).
	 *    If the fetch fails, the server is in an outage.
	 * 4. Another worker's reload has run for at most SLOW_RELOAD_MS. A copy fetched at most rules_cache_ms
	 *    + SLOW_RELOAD_MS ago that holds every invalidation made on this server checks the read, exact
	 *    reads included.
	 * 5. Otherwise every item is a miss until the reload is done.
	 *
	 * Only the worker holding the reload flag fetches the rules, or a worker whose APCu refuses to record
	 * the flag (see reload()). This method never answers "no rules" while a copy is held, because an
	 * outdated item would then read as fresh.
	 *
	 * @return array{0: ?Rules, 1: bool}
	 */
	protected function readRules(): array
	{
		// case 1: this worker's own copy is fresh
		if($this->rules !== null
			&& $this->rulesAreFresh())
		{
			return [$this->rules, false];
		}
		
		// case 1 again, after taking the shared copy if another worker stored a newer one
		$shared = $this->getSharedRules();
		if($shared !== null
			&& $this->adoptSharedRules($shared)
			&& $this->rulesAreFresh())
		{
			return [$this->rules, false];
		}
		
		// What the server knows: whether the last attempt to read the rules failed and when it ended, and
		// since when another worker has been reloading (null: the reload flag is free).
		[$outage, $endedAtMs] = $this->lastAttempt();
		$startedAtMs = $shared?->reloadStartedAt();
		
		// case 2: the rules cannot be read, or another worker's reload has run too long
		if($outage
			|| ($startedAtMs !== null && microtime(true) * 1000 - $startedAtMs > static::SLOW_RELOAD_MS))
		{
			// With the flag free and RETRY_MS passed since the last attempt, this worker tries again. If
			// that works, the read uses the new copy.
			if($startedAtMs === null
				&& $this->retryDue($endedAtMs))
			{
				$this->reload();
			}
			
			// the copy checks the read whatever its age; with none, every item is a miss without the lock
			return [$this->rules, $this->rules === null];
		}
		
		// Case 3: the flag is free, so this worker reloads. If the fetch fails, the server is in an
		// outage, and the read goes on as in case 2, with the copy held or with none.
		if($startedAtMs === null
			&& $this->reload())
		{
			return [$this->rules, $this->rules === null];
		}
		
		// Cases 4 and 5: another worker is reloading (it may have taken the flag just now). A copy young
		// enough that holds every local invalidation checks the read. Otherwise every item is a miss,
		// computed without the lock.
		return [$this->rules, $this->judgesWhileReloading() === false];
	}
	
	/**
	 * The copy of the rules a read would use now (see readRules()). Throws when there is none.
	 */
	protected function getRules(): Rules
	{
		[$rules] = $this->readRules();
		
		return $rules ?? throw new RedisException('Cannot load invalidation rules: none are held.');
	}
	
	/**
	 * Takes the reload flag and reloads the copy. It fetches the rules from the copy's newest id with
	 * XRANGE, one batch at a time, adds them to the copy, records when the fetch began, and stores the copy
	 * in APCu for the other workers. If the stream was started over, the new copy replaces the shared one.
	 * If APCu refuses to record the flag, this worker fetches on its own and writes nothing to APCu.
	 *
	 * Returns false when another worker holds the flag. Returns true when the copy this worker holds now is
	 * the one the read should use: just reloaded, taken from another worker that finished meanwhile, or,
	 * after a failed attempt, the copy held before.
	 */
	protected function reload(): bool
	{
		// Take the reload flag. Without a shared copy there is no flag, and this worker reloads for itself.
		$shared = $this->getSharedRules();
		$taken = $shared !== null
			? $shared->lead()
			: true;
		// another worker holds the flag
		if($taken === false)
		{
			return false;
		}
		
		// If APCu cannot record the flag, nobody could ever take it, and nobody would reload. So this
		// worker loads the rules itself, shares nothing, and warns once.
		$this->alone = $taken === null;
		if($this->alone)
		{
			$shared = null;
			if($this->aloneWarned === false)
			{
				$this->aloneWarned = true;
				$this->log('APCu refused the rule set\'s reload flag: this worker loads the invalidation rules on its own and shares nothing until APCu takes it.');
			}
		}
		
		try
		{
			// Look again: another worker may have reloaded, or tried and failed, between this worker's
			// first look and taking the flag. Then nothing is fetched.
			if($shared !== null)
			{
				// another worker stored a fresh copy meanwhile: use it
				if($this->adoptSharedRules($shared)
					&& $this->rulesAreFresh())
				{
					return true;
				}
				
				// another worker's attempt failed less than RETRY_MS ago: no new attempt yet, the copy
				// held is used
				[$outage, $endedAtMs] = $shared->lastAttempt();
				if($outage
					&& $this->retryDue($endedAtMs) === false)
				{
					return true;
				}
			}
			
			// reload into the copy held, or into a new empty one
			$rules = $this->rules ?? new Rules($this->rulesRetentionS * 1000, $this->rulesMaxTags);
			// The time is taken before the fetch. So a copy fetched after an invalidation was recorded on
			// this server holds that invalidation's rule (see behindLocalInvalidation()).
			$startedAtMs = microtime(true) * 1000;
			$reset = false;
			$succeeded = $this->fetchRules($rules, $reset);
			// record how the attempt went: a failure starts an outage, a success ends one
			$this->attemptEnded($startedAtMs, $succeeded);
			
			// keep the new copy and share it; it replaces the shared one when the stream started over
			if($succeeded)
			{
				$this->rules = $rules;
				$this->rulesFetchedAtMs = $startedAtMs;
				if($shared !== null)
				{
					$this->stored($shared->store($rules, $startedAtMs, $reset));
				}
				
				// fetched after this instance's own invalidations, so it holds them: nothing is left to share
				$this->unshared = false;
			}
			
			// after a failure too: the copy held before is what the read uses
			return true;
		}
		// the flag is let go whatever happened
		finally
		{
			$shared?->release();
		}
	}
	
	/**
	 * Brings $rules up to date with the stream, batch by batch. Each batch starts at the copy's newest id
	 * (inclusive), so a load from the start never holds more than one batch beside the copy. A batch that
	 * is not full, or brings nothing new, ends the loop. $reset says whether the stream no longer held the
	 * copy's newest id, so the copy was rebuilt from what the stream holds now. False when the stream could
	 * not be read (a connection error, or an error reply).
	 */
	protected function fetchRules(
		Rules $rules,
		bool &$reset,
	): bool
	{
		try
		{
			do
			{
				$before = $rules->last();
				$entries = $this->fetchRuleEntries($before);
				$rules->absorb($entries);
				$reset = $reset || $rules->wasReset();
			}
			while(count($entries) >= max(2, $this->rulesBatch)
				&& $rules->last() !== $before);
		}
		catch(RedisException|RedisClusterException $exception)
		{
			$this->log($exception);
			
			return false;
		}
		
		return true;
	}
	
	/**
	 * Takes the copy another worker shared, when it holds a newer rule than this worker's copy or was
	 * fetched later. A copy rebuilt after the stream started over has older ids but was fetched later, so
	 * it is taken too. True when a copy was taken, fresh or not.
	 */
	protected function adoptSharedRules(
		SharedRules $shared,
	): bool
	{
		// none shared, or one that cannot be read
		if(($loaded = $shared->load($this->rulesRetentionS * 1000, $this->rulesMaxTags)) === null)
		{
			return false;
		}
		
		[$rules, $fetchedAtMs] = $loaded;
		
		// the shared copy has no newer rule and was not fetched later: this worker's copy is kept
		if($this->rules !== null
			&& Rules::isNewerId($rules->last(), $this->rules->last()) === false
			&& $fetchedAtMs <= ($this->rulesFetchedAtMs ?? 0.0))
		{
			return false;
		}
		
		// a copy that ends at or after this instance's own rules holds them, so nothing is left to share
		$this->rules = $rules;
		$this->rulesFetchedAtMs = $fetchedAtMs;
		$this->unshared = false;
		
		return true;
	}
	
	/**
	 * Stores the copy this instance added its own rule to, so the other workers of this server take it
	 * instead of reloading. Its fetch time is the moment right before the call that added the rule (see
	 * addRule()).
	 */
	protected function shareHeldRules(): void
	{
		$shared = $this->getSharedRules();
		
		// nothing is shared without a shared copy, or when APCu refused the reload flag to this worker
		if($this->rules !== null
			&& $this->rulesFetchedAtMs !== null
			&& $shared !== null
			&& $this->alone === false)
		{
			$this->stored($shared->store($this->rules, $this->rulesFetchedAtMs));
		}
		
		$this->unshared = false;
	}
	
	/**
	 * Shares the copy that this instance's later invalidations went into, if there is one. A newer copy
	 * already in APCu stays (see SharedRules::store()).
	 */
	protected function shareUnshared(): void
	{
		if($this->unshared)
		{
			$this->shareHeldRules();
		}
	}
	
	/**
	 * Warns once per instance when APCu refused to store the copy. The other workers then keep using the
	 * older copy there, and nothing else would show it. A newer copy already there is not a refusal.
	 */
	protected function stored(
		?bool $stored,
	): void
	{
		// stored, or a newer copy was already there (see SharedRules::store()), or warned already
		if($stored !== null
			|| $this->storeWarned)
		{
			return;
		}
		
		$this->storeWarned = true;
		$this->log('APCu refused the rule set: the other workers of this server keep judging by the older one until it can be written.');
	}
	
	/**
	 * Whether the rules cannot be read right now (the last attempt failed), and when the last attempt to
	 * read the stream ended. With a shared copy this is the server's record, from any of its workers.
	 *
	 * @return array{0: bool, 1: ?float}
	 */
	protected function lastAttempt(): array
	{
		// no shared copy, or APCu refused the reload flag: this instance's own record
		$shared = $this->getSharedRules();
		if($shared === null
			|| $this->alone)
		{
			return [$this->outage, $this->attemptEndedAtMs];
		}
		
		[$outage, $endedAtMs] = $shared->lastAttempt();
		
		return [$outage, $endedAtMs];
	}
	
	/**
	 * While the rules cannot be read: may this worker try again? True when RETRY_MS has passed since the
	 * last attempt ended, or no attempt is known.
	 */
	protected function retryDue(
		?float $endedAtMs,
	): bool
	{
		return $endedAtMs === null
			|| microtime(true) * 1000 - $endedAtMs >= static::RETRY_MS;
	}
	
	/**
	 * Records how an attempt to read the stream went: for the whole server where the copy is shared,
	 * otherwise for this instance only
	 */
	protected function attemptEnded(
		float $startedAtMs,
		bool $succeeded,
	): void
	{
		$endedAtMs = microtime(true) * 1000;
		$shared = $this->getSharedRules();
		
		if($shared !== null
			&& $this->alone === false)
		{
			$shared->attemptEnded($startedAtMs, $succeeded, $endedAtMs);
			
			return;
		}
		
		$this->outage = $succeeded === false;
		$this->attemptEndedAtMs = $endedAtMs;
	}
	
	/**
	 * The one Redis call for the rules: the stream entries from $from (inclusive; from the start for
	 * Rules::NONE) to the newest, oldest first, at most one batch
	 *
	 * @return array id => fields
	 */
	protected function fetchRuleEntries(
		string $from,
	): array
	{
		if(($client = $this->getClient()) === null)
		{
			throw new RedisException('Cannot load invalidation rules: no Redis client.');
		}
		
		$entries = $client->xRange(
			$this->getRulesKey(),
			$from === Rules::NONE ? '-' : $from,
			'+',
			max(2, $this->rulesBatch),
		);
		
		// An error reply (BUSY, LOADING, MASTERDOWN answer false and throw nothing) is a failed read. The
		// copy held stays, and the server is in an outage (see readRules()). A stream that is gone answers
		// [], which counts as a lost stream, not as a failed read.
		if(is_array($entries) === false)
		{
			throw new RedisException(sprintf('Cannot load invalidation rules: %s',
				$client->getLastError() ?? 'no reply',
			));
		}
		
		return $entries;
	}
	
	/**
	 * The read of $id is over. An outdated item it found that no write replaced (no resolver, a null
	 * result, save: false, an exception, peek()) is removed now.
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
	 * Removes an outdated item, but only while it is still the item the read saw (Lua
	 * cache_versioned_drop_stale compares the "mark"). A delete() between the read and the removal left a
	 * tombstone, and a plain UNLINK would erase its epoch. The item's epoch stays for the guard window.
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
	 * At the end of the request: shares the copy that this instance's later invalidations went into, and
	 * removes the outdated items no afterRead() reached (a manual lockAndQueue() with no write after it).
	 */
	public function __destruct()
	{
		$this->shareUnshared();
		
		foreach($this->staleDrops as $id => $mark)
		{
			$this->dropStale($id, $mark);
		}
		$this->staleDrops = [];
	}
	
	/**
	 * Whether the copy may check a read without a reload: fetched less than rules_cache_ms ago, and not
	 * missing an invalidation made on this server
	 */
	protected function rulesAreFresh(): bool
	{
		return $this->rulesFetchedAtMs !== null
			&& microtime(true) * 1000 - $this->rulesFetchedAtMs < $this->rulesCacheMs
			&& $this->behindLocalInvalidation() === false;
	}
	
	/**
	 * Whether the copy may check a read while another worker reloads: fetched at most rules_cache_ms +
	 * SLOW_RELOAD_MS ago, and not missing an invalidation made on this server
	 */
	protected function judgesWhileReloading(): bool
	{
		return $this->rules !== null
			&& $this->rulesFetchedAtMs !== null
			&& microtime(true) * 1000 - $this->rulesFetchedAtMs <= $this->rulesCacheMs + static::SLOW_RELOAD_MS
			&& $this->behindLocalInvalidation() === false;
	}
	
	/**
	 * Whether the copy may lack an invalidation made on this server. That is so when it was fetched before
	 * the newest invalidation made here and ends before that rule's id. Then it checks no read until it is
	 * reloaded. A copy fetched after that invalidation holds its rule, because a fetch's time is taken
	 * before it starts. A copy that ends at or after the rule holds it, because addRule() added the rule
	 * from Redis's reply.
	 *
	 * The server keeps the newest time and the newest rule id as two separate values (see
	 * SharedRules::written()). Every rule made on this server is at or below both, so a copy that lacks
	 * any of them is caught. And a copy that ends before the newest id really lacks that rule, so no copy
	 * is held back for nothing.
	 */
	protected function behindLocalInvalidation(): bool
	{
		// no copy: there is nothing to check with, so nothing to hold back
		if($this->rules === null)
		{
			return false;
		}
		
		[$atUs, $id] = $this->newestWritten();
		
		// an invalidation was made here, the copy was fetched no later (in microseconds), and it ends
		// before that rule
		return $atUs !== null
			&& ($this->rulesFetchedAtMs === null || $this->rulesFetchedAtMs * 1000 <= $atUs)
			&& Rules::isNewerId($id, $this->rules->last());
	}
	
	/**
	 * The newest invalidation made by this instance or, through SharedRules, by any process of this
	 * server: its time in microseconds, rounded up as SharedRules records it, and its rule id, each the
	 * newest on its own
	 *
	 * @return array{0: ?int, 1: string}
	 */
	protected function newestWritten(): array
	{
		$atUs = $this->writtenAtMs !== null
			? (int)ceil($this->writtenAtMs * 1000)
			: null;
		$id = $this->writtenId;
		
		// with the server's record, the newer of each value counts
		if(($mark = $this->getSharedRules()?->writtenMark()) !== null)
		{
			$atUs = max($atUs ?? $mark[0], $mark[0]);
			$id = Rules::isNewerId($mark[1], $id) ? $mark[1] : $id;
		}
		
		return [$atUs, $id];
	}
	
	/**
	 * The copy shared with the other workers of this server; null when sharing is off, or APCu is not
	 * available
	 */
	protected function getSharedRules(): ?SharedRules
	{
		if($this->rulesSharedCache === false)
		{
			return null;
		}
		
		if($this->sharedRules === null)
		{
			// no APCu here: sharing stays off for this instance
			if($this->sharesRules() === false)
			{
				$this->rulesSharedCache = false;
				
				return null;
			}
			
			$this->sharedRules = $this->newSharedRules();
		}
		
		return $this->sharedRules;
	}
	
	/**
	 * Whether a copy can be shared here at all (see SharedRules::isAvailable())
	 */
	protected function sharesRules(): bool
	{
		return SharedRules::isAvailable();
	}
	
	protected function newSharedRules(): SharedRules
	{
		return new SharedRules(
			$this->getRulesKey(),
			$this->sharedRulesIdentity(),
		);
	}
	
	/**
	 * What tells this store's Redis apart from another one used on the same server. Two environments that
	 * share an FPM pool and a prefix must not share rules.
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
	 * Soft invalidation, for an item a rule has made outdated. Returns the value aged when the item holds a
	 * soft value (get(stale:, soft: true)) and the newest rule that reached it is younger than the value's
	 * stale time. Null otherwise: the item is a plain miss (also when the rules lost what the item saw).
	 * False when the Redis server's time cannot be read, so it cannot tell either way. Only here is the
	 * data of an outdated item decoded.
	 *
	 * @param array<string, mixed> $item the HMGET fetch() made
	 */
	protected function softly(
		array $item,
		Rules $rules,
	): Stale|false|null
	{
		// not a soft value: a plain miss
		$value = $this->serializer
			->unserialize($this->compressor->decompress($item[static::KEY_DATA]));
		if($value instanceof Stale === false
			|| $value->isSoft() === false)
		{
			return null;
		}
		
		// when the newest rule that reached the item was added; none known: a plain miss
		$tags = (string)$item[static::KEY_TAGS];
		$invalidatedAt = $rules->invalidatedAt(
			$tags === '' ? [] : explode(',', $tags),
			(string)$item[static::KEY_MARK],
		);
		if($invalidatedAt === null)
		{
			return null;
		}
		
		// the rule's time is the Redis server's, so it is compared with the server's time
		if(($now = $this->serverNowMs()) === null)
		{
			return false;
		}
		
		// still inside its stale time since that rule: served aged; past it: a plain miss
		return $now - $invalidatedAt < $value->staleFor * 1000
			? $value->invalidated()
			: null;
	}
	
	/**
	 * The Redis server's time in ms. That is the clock the rule ids come from (on a cluster, the node of
	 * the rules stream). It is read once per instance and then kept as an offset to the local clock. A host
	 * and a container can drift apart by seconds, and a soft value's stale time must not.
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
}
