<?php
declare(strict_types=1);

namespace Ovos\Cache\Store;

use Ovos\ArrayObject;
use Ovos\Cache\Compressor;
use Ovos\Cache\Computed;
use Ovos\Invoker;
use Ovos\Cache\MemoLock;
use Ovos\Cache\Policy;
use Ovos\Cache\Prefixer;
use Ovos\Cache\Serializer;
use Ovos\Cache\Stale;
use Closure;
use Throwable;

use function array_key_first;
use function count;
use function is_bool;
use function is_scalar;
use function max;
use function microtime;

/**
 * KeyValue
 *
 * @author Marcin Gil <mg@ovos.at>
 */
abstract class KeyValue
{
	protected Invoker $invoker;
	protected Prefixer $prefixer;
	protected Serializer $serializer;
	protected Compressor $compressor;
	protected ?MemoLock $memoLock = null;
	
	// Groups
	public const string GROUP_DEFAULT = 'core';
	public const string GROUP_TESTS = 'tests';
	public const string GROUP_BENCHMARKS = 'benchmarks';
	
	protected ?string $group = self::GROUP_DEFAULT;
	
	protected ?ArrayObject $config = null;
	
	/**
	 * How many misses an instance remembers, the oldest dropped first: a
	 * long-running worker misses keys it never writes
	 */
	public const int MISSES_MAX = 1000;
	
	/**
	 * The invalidation guard's window: how long an invalidation is remembered
	 * (a tombstone, a stamp, an epoch), and the longest recomputation the
	 * guard covers - a write that follows its own miss by more goes
	 * unguarded, as before the guard. 0 switches the guard off. Store option
	 * "invalidation_window_ms" (the tier's store_options)
	 * Unit: milliseconds
	 */
	protected int $invalidationWindowMs = 60000;
	
	/**
	 * What each miss of this instance saw, by item id - the write that follows
	 * it is refused when the key was invalidated since (see rememberMiss()).
	 * "stamp" is taken right before the computation (stampMiss()), null until
	 * then or when it could not be read
	 *
	 * @var array<string, array{epoch: string, stamp: ?string, at: float}>
	 */
	protected array $misses = [];
	
	/**
	 * Runs a stale value's refresh later (see setDeferrer()); null: inline
	 */
	protected ?Closure $deferrer = null;
	
	/**
	 * A forced refresh's read is under way: what it finds is a miss (see
	 * forceMiss())
	 */
	protected bool $refreshing = false;
	
	public function __construct(
		?string $prefix = null,
		?ArrayObject $config = null,
		?string $group = null,
	)
	{
		$this->config = $config;
		
		$this->invoker = new Invoker($this);
		$this->prefixer = new Prefixer(
			$prefix,
		);
		
		$this->serializer = new Serializer;
		$this->compressor = new Compressor(
			$config,
		);
		
		$this->setGroup($group);
		
		// the invalidation guard's window, any store's (see rememberMiss())
		$window = $config?->getPath(['store_options', 'invalidation_window_ms']);
		if($window !== null)
		{
			$this->setInvalidationWindowMs((int)$window);
		}
	}
	
	public function setGroup(
		?string $group,
	): static
	{
		$this->group = $group;
		
		return $this;
	}
	
	public function getGroup(): ?string
	{
		if($this->group !== null)
		{
			return $this->prefixer
				->prefix($this->group);
		}
		
		return null;
	}
	
	public function getPrefixer(): Prefixer
	{
		return $this->prefixer;
	}
	
	public function prefix(
		string $key,
		?string $prefix = null,
		string $separator = Prefixer::SEPARATOR_PREFIX,
	): string
	{
		return $this->prefixer
			->prefix($key, $prefix, $separator);
	}
	
	public function getInvalidationWindowMs(): int
	{
		return $this->invalidationWindowMs;
	}
	
	/**
	 * 0 switches the invalidation guard off: deletes remove, writes are not
	 * compared, nothing is stamped - the store as it was before the guard
	 */
	public function setInvalidationWindowMs(
		int $windowMs,
	): static
	{
		$this->invalidationWindowMs = max(0, $windowMs);
		
		return $this;
	}
	
	public function isGuarded(): bool
	{
		return $this->invalidationWindowMs > 0;
	}
	
	/**
	 * The invalidation guard - a value computed before an invalidation never
	 * lands after it (a "stale set"; memcache's leases solve the same race).
	 * A reader misses, recomputes from its source and writes; a delete or an
	 * invalidation landing between the reader's source read and its write
	 * would otherwise leave the old value cached for its whole TTL, the
	 * stampede lock notwithstanding (it orders the readers, not the writer
	 * that invalidates). So an invalidation leaves a mark for the window -
	 * the Redis stores a tombstone (the key holds only the "epoch" token) or
	 * a stamp (a tag, a clear), APCu a token - the miss remembers what it
	 * saw, and the write that follows it compares: refusing a cache write is
	 * always safe, the next reader recomputes. A write-through marks the key
	 * in turn, so an older recomputation in flight is refused too.
	 *
	 * Called by fetch() on a miss: $epoch is the key's mark (false: none).
	 * The stamp is NOT read here - fetch() runs before the stampede lock is
	 * tried, and every read on that path widens the window in which a late
	 * miss finds the lock free again (see stampMiss())
	 */
	protected function rememberMiss(
		string $id,
		mixed $epoch,
	): void
	{
		unset($this->misses[$id]);
		
		if($this->isGuarded() === false)
		{
			return;
		}
		
		// re-inserted, so insertion order is age and the oldest goes first
		$this->misses[$id] = [
			'epoch' => is_scalar($epoch) && is_bool($epoch) === false ? (string)$epoch : '',
			'stamp' => null,
			'at' => microtime(true),
		];
		if(count($this->misses) > static::MISSES_MAX)
		{
			unset($this->misses[array_key_first($this->misses)]);
		}
	}
	
	/**
	 * Stamps the miss of $id right before its value is computed - after the
	 * stampede lock and its second look (get() calls it in the resolver it
	 * hands MemoLock), so no read sits between the miss and the lock attempt.
	 * A miss stamped already keeps its stamp
	 */
	protected function stampMiss(
		string $id,
	): void
	{
		if(isset($this->misses[$id]) && $this->misses[$id]['stamp'] === null)
		{
			$this->misses[$id]['stamp'] = $this->missStamp();
		}
	}
	
	/**
	 * What a miss stamps beside the epoch, so that an invalidation the key's
	 * own mark cannot show is seen too: the versioned stores' rules
	 * watermark, the tag-index stores' server time (their tag stamps). ''
	 * when the store needs none, null when it could not be read
	 */
	protected function missStamp(): ?string
	{
		return '';
	}
	
	/**
	 * The miss a write follows - taken, so it guards one write only; none
	 * when it is older than the window
	 *
	 * @return array{epoch: string, stamp: ?string, at: float}|null
	 */
	protected function takeMiss(
		string $id,
	): ?array
	{
		$miss = $this->misses[$id] ?? null;
		unset($this->misses[$id]);
		
		if($miss === null || (microtime(true) - $miss['at']) * 1000 > $this->invalidationWindowMs)
		{
			return null;
		}
		
		return $miss;
	}
	
	/**
	 * Where a stale value's refresh runs (get(stale:), see revalidate()):
	 * the deferrer is handed the refresh as a closure to call later -
	 * Service\Cache gives an HTTP request's stores Application::afterResponse(),
	 * so it runs once the client has its response. Null (a CLI, a worker, a
	 * store built by hand): inline
	 */
	public function setDeferrer(
		?Closure $deferrer,
	): static
	{
		$this->deferrer = $deferrer;
		
		return $this;
	}
	
	public function getDeferrer(): ?Closure
	{
		return $this->deferrer;
	}
	
	/**
	 * Reads an item by its prefixed id: its value, or null for a miss
	 */
	abstract protected function fetch(
		string $id,
	): mixed;
	
	/**
	 * The prefixed id $key is stored - and locked (MemoLock) - under
	 */
	abstract public function itemId(
		string $key,
	): string;
	
	/**
	 * Reads $key as it is stored, without the stampede lock: a value written
	 * with a stale time comes back as its Stale, fresh or aged (get() unwraps
	 * it) - for a caller that serves an aged value itself while it refreshes
	 * it (Plugins\Cache\Page). An aged read is a miss to the invalidation
	 * guard, so the write that refreshes it is guarded
	 */
	public function peek(
		string $key,
	): mixed
	{
		return $this->fetch($this->itemId($key));
	}
	
	/**
	 * What fetch() found: a value past its fresh time (written with a stale
	 * time, see Stale) is a miss to the guard - the write that refreshes it
	 * follows this read - so it is remembered with the epoch the item
	 * carries ($epoch, or a Closure reading it when that costs a read of its
	 * own); anything else is a hit
	 */
	protected function found(
		string $id,
		mixed $value,
		mixed $epoch,
	): mixed
	{
		if(($value instanceof Stale && $value->isFresh() === false)
			|| $this->refreshing)
		{
			$this->rememberMiss($id, $epoch instanceof Closure ? $epoch() : $epoch);
		}
		else
		{
			unset($this->misses[$id]);
		}
		
		return $value;
	}
	
	/**
	 * A read as a caller sees it: a value written with a stale time while it
	 * is fresh, nothing once it is not - a read that does not ask for stale
	 * values takes it for a miss
	 */
	protected function fresh(
		mixed $data,
	): mixed
	{
		if($data instanceof Stale)
		{
			return $data->isFresh()
				? $data->value
				: null;
		}
		
		return $data;
	}
	
	/**
	 * What get() serves from a read: a value, or a fresh one written with a
	 * stale time, as it is; one past its fresh time while it is refreshed,
	 * when the caller asked for it ($refresh, see revalidate()). Null: a
	 * miss - a value past its fresh time to a read without stale: included
	 */
	protected function served(
		string $id,
		mixed $data,
		?Closure $refresh,
	): mixed
	{
		if($data instanceof Stale === false || $data->isFresh())
		{
			return $this->fresh($data);
		}
		
		// past its stale time a value is a miss - one kept for errors waits
		// for a computation that fails (see computeOrFallback())
		if($data->isServable() === false)
		{
			return null;
		}
		
		return $refresh !== null
			? $this->revalidate($id, $data->value, $refresh)
			: null;
	}
	
	/**
	 * A value kept for errors that the read found past its fresh time - the
	 * fallback of the miss's computation, when the caller asked for one
	 * (get(staleIfError:))
	 */
	protected function errorFallback(
		mixed $data,
		int $staleIfError,
	): ?Stale
	{
		return $staleIfError > 0
			&& $data instanceof Stale
			&& $data->isKeptForErrors()
				? $data
				: null;
	}
	
	/**
	 * The miss's computation: with a value kept for errors, one that throws
	 * hands that value back instead - logged, the lock freed (nothing was
	 * written)
	 */
	protected function computeOrFallback(
		string $id,
		Closure $compute,
		?Stale $fallback,
	): mixed
	{
		if($fallback === null)
		{
			return $compute();
		}
		
		try
		{
			return $compute();
		}
		catch(Throwable $throwable)
		{
			$this->log($throwable);
			$this->getMemoLock()
				->releaseActiveLock($id);
			
			return $fallback->value;
		}
	}
	
	/**
	 * Reads $id for a forced refresh (get(refresh:)): whatever it finds is
	 * remembered as a miss, so the write that refreshes it is guarded like a
	 * miss's - a delete or an invalidation while it computes refuses it
	 */
	protected function forceMiss(
		string $id,
	): void
	{
		$this->refreshing = true;
		try
		{
			$this->fetch($id);
		}
		finally
		{
			$this->refreshing = false;
		}
	}
	
	/**
	 * Stale-while-revalidate: returns the stale value at once and refreshes
	 * the item - after the response where there is one (the deferrer), inline
	 * otherwise, the caller then getting what was computed. One process
	 * refreshes: MemoLock elects it when the refresh runs, without waiting
	 * (tryLock()), and it reads the item once more - another process may have
	 * refreshed it meanwhile, or an invalidation removed it (the next read
	 * computes). That read remembers the epoch the item carries, so the
	 * refresh's write is guarded like a miss's: an invalidation during the
	 * refresh refuses it
	 */
	protected function revalidate(
		string $id,
		mixed $stale,
		Closure $refresh,
	): mixed
	{
		$run = function() use ($id, $refresh): mixed
		{
			$memoLock = $this->getMemoLock();
			if($memoLock->tryLock($id) === false)
			{
				// another process refreshes it
				return null;
			}
			
			try
			{
				$data = $this->fetch($id);
				if($data instanceof Stale === false || $data->isFresh())
				{
					// refreshed meanwhile, or gone: nothing to refresh
					return $this->fresh($data);
				}
				
				$this->stampMiss($id);
				
				return $refresh();
			}
			catch(Throwable $throwable)
			{
				// the stale value stays: the next read past its fresh time tries again
				$this->log($throwable);
				
				return null;
			}
			finally
			{
				// set() releases it when it writes, a refused write too; twice is a no-op
				$memoLock->releaseActiveLock($id);
			}
		};
		
		if($this->deferrer !== null)
		{
			($this->deferrer)($run);
			
			return $stale;
		}
		
		return $run() ?? $stale;
	}
	
	/**
	 * Logs events (messages/errors/exceptions) - a store with no connection
	 * to log through drops them
	 */
	public function log(
		...$event,
	): static
	{
		return $this;
	}
	
	abstract public function getMemoLock(): MemoLock;
	
	public function setQueueEnabled(
		bool $enabled,
	): static
	{
		$this->getMemoLock()
			->setQueueEnabled($enabled);
		
		return $this;
	}
	
	public function isQueueEnabled(): bool
	{
		return $this->getMemoLock()
			->isQueueEnabled();
	}
	
	abstract public function lockAndQueue(
		string $key,
	): mixed;
	
	abstract public function releaseActiveLock(
		string $key,
	): bool;
	
	abstract public function renewLock(
		string $key,
	): bool;
	
	/**
	 * The value under $key - on a miss computed by $resolver (one process
	 * computes, the others wait for its result: MemoLock) and stored for $ttl
	 * seconds with $tags (a store with tags). The resolver is called as
	 * function(KeyValue $store, string $key) and returns the value or a
	 * Computed (see setFromResolver()). The options come by name after $tags,
	 * read into a Policy - queue:, queueLockTtlMs:, stale:, soft:, refresh:,
	 * staleIfError: - and an unknown one throws. The one signature of every
	 * store: an override copies it once, a new option never changes it
	 */
	abstract public function get(
		string $key,
		?Closure $resolver = null,
		int $ttl = 0,
		array $tags = [],
		mixed ...$options,
	): mixed;
	
	/**
	 * Computes and stores: the resolver is called as
	 * function(KeyValue $store, string $key) and returns the value - stored
	 * as the call says - or a Computed: its own TTL, its own tags, or nothing
	 * to store (save: false hands the value back, nothing is written and the
	 * lock is freed at once). With the policy's stale time or time kept for
	 * errors the value is stored as a Stale record (Stale::wrap())
	 */
	public function setFromResolver(
		string $key,
		?Closure $resolver,
		int $ttl = 0,
		array $tags = [],
		?Policy $policy = null,
	): mixed
	{
		if($resolver === null)
		{
			return null;
		}
		
		$value = $resolver($this, $key);
		$save = true;
		if($value instanceof Computed)
		{
			$ttl = $value->ttl ?? $ttl;
			$tags = $value->tags ?? $tags;
			$save = $value->save;
			$value = $value->value;
		}
		
		if($save === false)
		{
			// nothing written: the lock goes now, not at its TTL
			$this->releaseActiveLock($key);
		}
		else if($value !== null)
		{
			$policy ??= new Policy();
			[$stored, $storedTtl] = Stale::wrap($value, $ttl, $policy->stale, $policy->soft, $policy->staleIfError);
			$this->storeComputed($key, $stored, $storedTtl, $tags);
		}
		
		return $value;
	}
	
	/**
	 * Stores what setFromResolver() computed - a store with tags stores the
	 * tags with it (see Tags)
	 */
	protected function storeComputed(
		string $key,
		mixed $value,
		int $ttl,
		array $tags,
	): bool
	{
		return $this->set($key, $value, $ttl);
	}
	
	abstract public function set(
		string $key,
		mixed $value,
		int $ttl = 0,
	): bool;
}
