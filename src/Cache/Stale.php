<?php
declare(strict_types=1);

namespace Ovos\Cache;

use function microtime;

/**
 * Stale
 *
 * A value written by get(stale:) - stale-while-revalidate. It is fresh
 * until $freshUntil and kept for the stale time past it (the item's TTL is
 * ttl + stale): a read in that time returns the value at once while one
 * process refreshes it. Stored as the item's value, so every store carries
 * it the same way; a read without stale: takes it for a miss once it is no
 * longer fresh.
 *
 * A soft value (get(stale:, soft: true)) may be served the same way for
 * $staleFor seconds after a tag invalidation reached it - soft
 * invalidation; its fresh time is INF when it was written without a TTL,
 * so it ages by an invalidation only. A hard value, and every delete() or
 * clear(), is a miss at once.
 *
 * A value written with get(staleIfError:) is kept $errorFor seconds past
 * its stale time (the item's TTL is ttl + stale + staleIfError): a read in
 * that time is a miss whose computation, if it fails, hands back this
 * value instead of the exception
 *
 * @author Marcin Gil <mg@ovos.at>
 */
final readonly class Stale
{
	/**
	 * @param float $freshUntil when it stops being fresh (microtime; INF: never by age)
	 * @param int $staleFor the stale time: seconds it may be served past its age, or past a soft invalidation
	 * @param bool $soft a tag invalidation softens to the stale time too
	 * @param int $errorFor seconds past the stale time it is kept for a computation that fails
	 * @param bool $invalidated aged by a soft invalidation (its window is the store's), not by time
	 */
	public function __construct(
		public mixed $value,
		public float $freshUntil,
		public int $staleFor = 0,
		public bool $soft = false,
		public int $errorFor = 0,
		public bool $invalidated = false,
	)
	{
	}
	
	public function isFresh(): bool
	{
		return microtime(true) < $this->freshUntil;
	}
	
	/**
	 * Whether an aged value may still be served while it is refreshed: a
	 * value kept for errors only inside its stale time (past it, it is kept
	 * for a failing computation alone); any other value as long as it is
	 * there - its TTL, or a soft invalidation's window, ends the stale time
	 */
	public function isServable(): bool
	{
		return $this->errorFor <= 0
			|| $this->invalidated
			|| microtime(true) < $this->freshUntil + $this->staleFor;
	}
	
	/**
	 * The same value, past its fresh time - how a read hands out a soft value
	 * an invalidation reached: served while it is refreshed, a miss to a read
	 * without stale:
	 */
	public function aged(): self
	{
		return new self($this->value, 0.0, $this->staleFor, $this->soft, $this->errorFor, true);
	}
	
	/**
	 * A Stale written before the stale time and the soft flag were stored
	 * reads as a hard one; one written before staleIfError as kept for no
	 * error
	 */
	public function __unserialize(
		array $data,
	): void
	{
		$this->value = $data['value'] ?? null;
		$this->freshUntil = (float)($data['freshUntil'] ?? 0.0);
		$this->staleFor = (int)($data['staleFor'] ?? 0);
		$this->soft = (bool)($data['soft'] ?? false);
		$this->errorFor = (int)($data['errorFor'] ?? 0);
		$this->invalidated = (bool)($data['invalidated'] ?? false);
	}
}
