<?php
declare(strict_types=1);

namespace Ovos\Cache\Store;

use Ovos\ArrayObject;
use Ovos\Cache\Compressor;
use Ovos\Invoker;
use Ovos\Cache\MemoLock;
use Ovos\Cache\Prefixer;
use Ovos\Cache\Serializer;
use Closure;

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
	
	abstract public function get(
		string $key,
		?Closure $resolver = null,
		int $ttl = 0,
	): mixed;
	
	public function setFromResolver(
		string $key,
		?Closure $resolver,
		int $ttl = 0,
	): mixed
	{
		$value = $this->invoker
			->invoke($resolver);
		
		if($value !== null)
		{
			$this->set($key, $value, $ttl);
		}
		
		return $value;
	}
	
	abstract public function set(
		string $key,
		mixed $value,
		int $ttl = 0,
	): bool;
}
