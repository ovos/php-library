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
 * clear(), is a miss at once
 *
 * @author Marcin Gil <mg@ovos.at>
 */
final readonly class Stale
{
	/**
	 * @param float $freshUntil when it stops being fresh (microtime; INF: never by age)
	 * @param int $staleFor the stale time: seconds it may be served past its age, or past a soft invalidation
	 * @param bool $soft a tag invalidation softens to the stale time too
	 */
	public function __construct(
		public mixed $value,
		public float $freshUntil,
		public int $staleFor = 0,
		public bool $soft = false,
	)
	{
	}
	
	public function isFresh(): bool
	{
		return microtime(true) < $this->freshUntil;
	}
	
	/**
	 * The same value, past its fresh time - how a read hands out a soft value
	 * an invalidation reached: served while it is refreshed, a miss to a read
	 * without stale:
	 */
	public function aged(): self
	{
		return new self($this->value, 0.0, $this->staleFor, $this->soft);
	}
	
	/**
	 * A Stale written before the stale time and the soft flag were stored
	 * reads as a hard one
	 */
	public function __unserialize(
		array $data,
	): void
	{
		$this->value = $data['value'] ?? null;
		$this->freshUntil = (float)($data['freshUntil'] ?? 0.0);
		$this->staleFor = (int)($data['staleFor'] ?? 0);
		$this->soft = (bool)($data['soft'] ?? false);
	}
}
