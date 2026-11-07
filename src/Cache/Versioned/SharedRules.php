<?php
declare(strict_types=1);

namespace Ovos\Cache\Versioned;

use WeakMap;

use function apcu_add;
use function apcu_cas;
use function apcu_delete;
use function apcu_enabled;
use function apcu_exists;
use function apcu_fetch;
use function apcu_store;
use function ceil;
use function function_exists;
use function hash;
use function intdiv;
use function is_array;
use function is_float;
use function is_int;
use function max;
use function microtime;
use function random_int;
use function register_shutdown_function;

/**
 * SharedRules
 *
 * An invalidation does not delete items. It adds a rule to a stream in Redis, such as "items tagged
 * product:7 written before this rule are outdated", and every PHP worker checks the items it reads against
 * a copy of those rules (see RedisVersioned). This class keeps one such copy in APCu for all the workers
 * that share that APCu (called a server here), so a new request takes it instead of loading the stream.
 *
 * Under PHP-FPM every request is a new process with a new store instance. Without a shared copy, it would
 * load every rule of the retention window before its first read. With one, the copy (see Rules) is loaded
 * once per server and then only kept up to date: one worker fetches the rules added since the copy's
 * newest one and stores the result here for the others.
 *
 * Beside the copy, APCu holds what the workers of the server must agree on:
 * - the reload flag: only the worker holding it reloads the copy (see lead());
 * - the result of the last attempt to read the stream, and when it ended (see attemptEnded());
 * - the time and rule id of the newest invalidation made on this server (see written()).
 *
 * An example on one server. At 12:00:00.000 worker A invalidates the tag product:7. A's copy ended right
 * before the new rule, so A adds the rule to its copy, stores the copy here, and only then records the
 * invalidation with written(). A's next read of an item tagged product:7 is a miss. Worker B reads such
 * an item at 12:00:00.100. B's own copy is young, but it was fetched before the recorded invalidation and
 * lacks its rule, so B does not use it. B takes the copy A stored, which holds the rule, and the item is
 * a miss without any fetch. Had A's copy not ended right before the new rule, A would have stored
 * nothing, and B's read would have needed a reload from Redis (see RedisVersioned::readRules()).
 *
 * When something goes wrong:
 * - Redis does not answer. The failed attempt is recorded here, every worker keeps using its copy, and
 *   one worker tries again RedisVersioned::RETRY_MS later. A failure from an attempt that began before
 *   the last success is ignored, because that success read the stream later.
 * - Another worker is reloading. It holds the flag, so nobody else fetches. reloadStartedAt() says since
 *   when, and a reload running longer than RedisVersioned::SLOW_RELOAD_MS counts as if Redis were down.
 * - Two workers race. store() never puts a copy over one with newer rules (unless the stream started
 *   over), and written() only ever raises the recorded time and id, so the later invalidation's record
 *   stands.
 * - A reloader dies. Its flag is free again after LEASE_MS, and a fatal error lets it go at shutdown.
 * - APCu is full and refuses the flag. lead() answers null, and that worker loads the rules on its own.
 *
 * More in README.CACHE.md: "RedisVersioned" under "Store differences", the parts "The rule set while it
 * reloads" (its paragraph "The mechanism") and "An invalidation made on this server".
 *
 * The reload flag is a lease: one integer, holding the millisecond the reload began and a random number.
 * One worker at a time takes it, and only that worker releases it. It lasts longer than any reload,
 * because it exists for a reloader that dies, not for one that hangs.
 *
 * All entries are keyed by the rules key and a hash of the connection, so two environments that share one
 * FPM pool and one prefix never read each other's rules. Whether a copy is fresh is decided by the fetch
 * time stored with it. The APCu TTL only cleans up entries of groups nobody reads any more.
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class SharedRules
{
	/**
	 * How long an entry nobody touches stays in APCu. Whether a copy is fresh is decided by the fetch time
	 * stored inside it, not by this.
	 * Unit: seconds
	 */
	public const int TTL_S = 86400;
	
	/**
	 * How long a lease holds the flag: longer than any reload whose Redis read timeout is kept short, and
	 * as long as PHP-FPM's default max_execution_time
	 * Unit: milliseconds
	 */
	public const int LEASE_MS = 30000;
	
	/**
	 * A lease is one integer, so APCu can take and release it in one step: the millisecond the reload
	 * began times this number, plus a random number below it
	 */
	protected const int LEASE_NONCE = 1000000;
	
	protected const string SEPARATOR_IDENTITY = '@';
	
	protected const string SUFFIX_LEASE = ':lease';
	
	protected const string SUFFIX_MARK_AT_US = ':mark-at-us';
	
	protected const string SUFFIX_MARK_ID = ':mark-id';
	
	/**
	 * How many low bits of a packed rule id hold the id's sequence part (see packId()). The millisecond
	 * part keeps the other 43 bits, enough until the year 2248.
	 */
	protected const int SEQUENCE_BITS = 20;
	
	protected const string SUFFIX_LAST_ATTEMPT = ':last-attempt';
	
	// Entry shape
	protected const string KEY_RULES = 'rules';
	protected const string KEY_FETCHED_AT_MS = 'fetched_at_ms';
	protected const string KEY_OUTAGE = 'outage';
	protected const string KEY_ENDED_AT_MS = 'ended_at_ms';
	protected const string KEY_SUCCESS_AT_MS = 'success_at_ms';
	
	protected string $key;
	
	/**
	 * The APCu key of the reload flag: a lease, or 0 when the flag is free (see lead())
	 */
	protected string $leaseKey;
	
	/**
	 * The newest invalidation made on this server, as two integers that only ever go up: its time in
	 * microseconds, and its rule id (see written())
	 */
	protected string $markAtUsKey;
	
	protected string $markIdKey;
	
	/**
	 * The result of the last attempt to read the stream (see attemptEnded())
	 */
	protected string $attemptKey;
	
	/**
	 * The lease this instance holds; 0 when it holds none
	 */
	protected int $lease = 0;
	
	/**
	 * The instances of this process that hold a lease, so the flag is let go when the process ends. A
	 * WeakMap, so it keeps no instance alive.
	 *
	 * @var WeakMap<SharedRules, true>|null
	 */
	protected static ?WeakMap $holders = null;
	
	/**
	 * @param string $rulesKey the stream key, already carrying the prefix and the group
	 * @param string $identity what tells this connection apart from another one on the same server
	 */
	public function __construct(
		string $rulesKey,
		string $identity = '',
	)
	{
		$this->key = $rulesKey
			. static::SEPARATOR_IDENTITY
			. hash('xxh64', $identity);
		$this->leaseKey = $this->key . static::SUFFIX_LEASE;
		$this->markAtUsKey = $this->key . static::SUFFIX_MARK_AT_US;
		$this->markIdKey = $this->key . static::SUFFIX_MARK_ID;
		$this->attemptKey = $this->key . static::SUFFIX_LAST_ATTEMPT;
	}
	
	/**
	 * APCu is compiled in and switched on for this SAPI
	 */
	public static function isAvailable(): bool
	{
		return function_exists('apcu_enabled')
			&& apcu_enabled();
	}
	
	public function getKey(): string
	{
		return $this->key;
	}
	
	/**
	 * The stored copy and the time its fetch began, or null when there is none (or one that cannot be
	 * read)
	 *
	 * @return array{Rules, float}|null
	 */
	public function load(
		int $retentionMs,
		int $maxTags = Rules::MAX_TAGS,
	): ?array
	{
		$entry = $this->fetch($this->key);
		
		if(is_array($entry) === false)
		{
			return null;
		}
		
		$fetchedAtMs = $entry[static::KEY_FETCHED_AT_MS] ?? null;
		$rules = Rules::fromArray($entry[static::KEY_RULES] ?? null, $retentionMs, $maxTags);
		
		if($rules === null
			|| (is_float($fetchedAtMs) === false && is_int($fetchedAtMs) === false))
		{
			return null;
		}
		
		return [$rules, (float)$fetchedAtMs];
	}
	
	/**
	 * Stores the copy for the other workers, unless another worker stored a newer one meanwhile. Two
	 * reloads can race, and the slower one must not replace the copy with one that lacks rules the faster
	 * one fetched. A copy built after the stream started over replaces the one there, whatever its ids
	 * ($replace). True when stored, false when a newer copy is there, null when APCu refused the write.
	 */
	public function store(
		Rules $rules,
		float $fetchedAtMs,
		bool $replace = false,
	): ?bool
	{
		// the copy stored now, unless it is replaced anyway
		$current = $replace
			? null
			: $this->fetch($this->key);
		$held = is_array($current)
			? Rules::fromArray($current[static::KEY_RULES] ?? null, 0)
			: null;
		
		// the stored copy has newer rules: it stays
		if($held !== null
			&& Rules::isNewerId($held->last(), $rules->last()))
		{
			return false;
		}
		
		$stored = $this->write($this->key, [
			static::KEY_RULES => $rules->toArray(),
			static::KEY_FETCHED_AT_MS => $fetchedAtMs,
		]);
		
		return $stored
			? true
			: null;
	}
	
	/**
	 * Records that a process of this server added rule $id at $atMs (its clock, taken after Redis added
	 * the rule). A copy fetched before then that ends before $id may lack that rule (see writtenMark()).
	 * The id and the time are each raised in one APCu step and never lowered. So when two invalidations
	 * race on one server, the earlier one can never overwrite the later one's record.
	 */
	public function written(
		float $atMs,
		string $id,
	): void
	{
		$this->raise($this->markIdKey, static::packId($id));
		// Whole microseconds, rounded up: a fetch that began within that microsecond counts as begun
		// before the invalidation, so its copy is not trusted to hold the rule. A fetch begun a fraction
		// of a millisecond later is trusted: whole ms would make every read of that millisecond reload.
		$this->raise($this->markAtUsKey, (int)ceil($atMs * 1000));
	}
	
	/**
	 * The newest invalidation made on this server: the newest time (microseconds, this server's clock)
	 * and the newest rule id that written() recorded, each on its own. Null when none is recorded.
	 *
	 * @return array{int, string}|null
	 */
	public function writtenMark(): ?array
	{
		$atUs = $this->fetch($this->markAtUsKey);
		$id = $this->fetch($this->markIdKey);
		
		if(is_int($atUs) === false
			|| is_int($id) === false)
		{
			return null;
		}
		
		return [$atUs, static::unpackId($id)];
	}
	
	/**
	 * A rule id as one integer that sorts the same way the id does: the millisecond in the high bits, the
	 * sequence in the low SEQUENCE_BITS bits. A sequence too big to fit is stored as the next millisecond.
	 * That is later than the id, never earlier, so the recorded id is never older than the rule it records.
	 */
	public static function packId(
		string $id,
	): int
	{
		[$ms, $sequence] = Rules::split($id);
		if($sequence >= 1 << static::SEQUENCE_BITS)
		{
			return ($ms + 1) << static::SEQUENCE_BITS;
		}
		
		return ($ms << static::SEQUENCE_BITS) | $sequence;
	}
	
	public static function unpackId(
		int $packed,
	): string
	{
		return ($packed >> static::SEQUENCE_BITS) . '-' . ($packed & ((1 << static::SEQUENCE_BITS) - 1));
	}
	
	/**
	 * Takes the reload flag for this instance. True when it holds the flag now, false when another
	 * worker's lease holds it, null when APCu refused to record it (full, under an apc.ttl that keeps it
	 * from expunging entries).
	 */
	public function lead(): ?bool
	{
		// this instance holds it already
		if($this->lease !== 0)
		{
			return true;
		}
		
		// this instance's lease: now in ms times LEASE_NONCE, plus a random number below it
		$mine = (int)(microtime(true) * 1000) * static::LEASE_NONCE + random_int(1, static::LEASE_NONCE - 1);
		$taken = $this->take($mine);
		if($taken !== true)
		{
			return $taken;
		}
		
		$this->lease = $mine;
		
		// A reloader that dies of a fatal error lets go of the flag too. One shutdown function per process
		// is enough, whatever the number of instances.
		if(self::$holders === null)
		{
			self::$holders = new WeakMap();
			register_shutdown_function(static function(): void
			{
				// copied first, because release() removes each holder from the map
				$holders = [];
				foreach(self::$holders ?? [] as $holder => $held)
				{
					$holders[] = $holder;
				}
				
				foreach($holders as $holder)
				{
					$holder->release();
				}
			});
		}
		
		self::$holders[$this] = true;
		
		return true;
	}
	
	/**
	 * Lets go of the flag if this instance still holds it. If its lease ran out and another worker took
	 * the flag, the flag stays with that worker.
	 */
	public function release(): void
	{
		if($this->lease === 0)
		{
			return;
		}
		
		$mine = $this->lease;
		$this->lease = 0;
		unset(self::$holders[$this]);
		
		// set back to 0 only while the flag still holds this instance's lease
		$this->swap($this->leaseKey, $mine, 0);
	}
	
	/**
	 * When the reload running now began (ms, this server's clock); null when the flag is free or its lease
	 * ran out
	 */
	public function reloadStartedAt(): ?float
	{
		$held = $this->fetch($this->leaseKey);
		
		if(is_int($held) === false
			|| $held === 0
			|| static::expired($held))
		{
			return null;
		}
		
		return (float)intdiv($held, static::LEASE_NONCE);
	}
	
	/**
	 * The result of the last attempt to read the stream: whether it failed (so the rules cannot be read
	 * now, an outage), when it ended, and when the last successful fetch began (ms). Each time is null
	 * when none is known.
	 *
	 * @return array{bool, float|null, float|null}
	 */
	public function lastAttempt(): array
	{
		$entry = $this->fetch($this->attemptKey);
		if(is_array($entry) === false)
		{
			return [false, null, null];
		}
		
		$endedAtMs = $entry[static::KEY_ENDED_AT_MS] ?? null;
		$successAtMs = $entry[static::KEY_SUCCESS_AT_MS] ?? null;
		
		return [
			($entry[static::KEY_OUTAGE] ?? null) === true,
			is_float($endedAtMs) || is_int($endedAtMs) ? (float)$endedAtMs : null,
			is_float($successAtMs) || is_int($successAtMs) ? (float)$successAtMs : null,
		];
	}
	
	/**
	 * Records an attempt to read the stream that ran from $startedAtMs to $endedAtMs. A success ends an
	 * outage. A failure starts one, unless the attempt began before the last success: that success read
	 * the stream later, so the failure is out of date.
	 */
	public function attemptEnded(
		float $startedAtMs,
		bool $succeeded,
		float $endedAtMs,
	): void
	{
		[, , $successAtMs] = $this->lastAttempt();
		
		// a failure older than the last success is ignored
		if($succeeded === false
			&& $successAtMs !== null
			&& $startedAtMs < $successAtMs)
		{
			return;
		}
		
		// the start of the newest success is kept
		$this->write($this->attemptKey, [
			static::KEY_OUTAGE => $succeeded === false,
			static::KEY_ENDED_AT_MS => $endedAtMs,
			static::KEY_SUCCESS_AT_MS => $succeeded
				? max($successAtMs ?? $startedAtMs, $startedAtMs)
				: $successAtMs,
		]);
	}
	
	/**
	 * Drops the copy and everything stored beside it, because the stream it described is gone
	 */
	public function forget(): bool
	{
		$this->remove($this->leaseKey);
		$this->remove($this->markAtUsKey);
		$this->remove($this->markIdKey);
		$this->remove($this->attemptKey);
		
		return $this->remove($this->key);
	}
	
	/**
	 * Takes the flag: added where there is none, or swapped in one step for a free (0) or expired lease.
	 * An add that fails while no key is there means APCu refused it (null).
	 */
	protected function take(
		int $mine,
	): ?bool
	{
		if($this->add($this->leaseKey, $mine))
		{
			return true;
		}
		
		$held = $this->fetch($this->leaseKey);
		if(is_int($held) === false)
		{
			// No lease there, or a value that is not a lease: it is removed, and the workers race to add
			// theirs.
			if($held !== null)
			{
				$this->remove($this->leaseKey);
			}
			
			if($this->add($this->leaseKey, $mine))
			{
				return true;
			}
			
			// the add failed: another worker's lease is there now (false), or APCu refused it (null)
			return $this->exists($this->leaseKey)
				? false
				: null;
		}
		
		// a free or expired lease is swapped for this worker's, in one step
		return ($held === 0 || static::expired($held))
			&& $this->swap($this->leaseKey, $held, $mine);
	}
	
	/**
	 * Raises the integer under $key to $value, never lowering it. It is added where there is none, or
	 * swapped in one step while the value there is lower. A swap that fails means another worker wrote a
	 * value meanwhile, so it looks again. If the add fails and nothing is there, nothing is recorded.
	 */
	protected function raise(
		string $key,
		int $value,
	): void
	{
		while($this->add($key, $value) === false)
		{
			$held = $this->fetch($key);
			if($held === null)
			{
				return;
			}
			
			// not a value this code wrote: removed, and the add is tried again
			if(is_int($held) === false)
			{
				$this->remove($key);
				
				continue;
			}
			
			// the value there is as high already, or the swap worked
			if($held >= $value
				|| $this->swap($key, $held, $value))
			{
				return;
			}
		}
	}
	
	/**
	 * Whether a lease is older than LEASE_MS
	 */
	protected static function expired(
		int $lease,
	): bool
	{
		return microtime(true) * 1000 - intdiv($lease, static::LEASE_NONCE) >= static::LEASE_MS;
	}
	
	/**
	 * An entry as stored, or null when there is none
	 */
	protected function fetch(
		string $key,
	): mixed
	{
		$entry = apcu_fetch($key, $success);
		
		return $success === true
			? $entry
			: null;
	}
	
	protected function add(
		string $key,
		mixed $entry,
	): bool
	{
		return apcu_add($key, $entry, static::TTL_S) === true;
	}
	
	protected function write(
		string $key,
		mixed $entry,
	): bool
	{
		return apcu_store($key, $entry, static::TTL_S) === true;
	}
	
	/**
	 * Replaces $old with $new where $key holds $old, in one step
	 */
	protected function swap(
		string $key,
		int $old,
		int $new,
	): bool
	{
		return apcu_cas($key, $old, $new) === true;
	}
	
	protected function exists(
		string $key,
	): bool
	{
		return apcu_exists($key) === true;
	}
	
	protected function remove(
		string $key,
	): bool
	{
		return apcu_delete($key) === true;
	}
}
