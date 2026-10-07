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
 * longer fresh
 *
 * @author Marcin Gil <mg@ovos.at>
 */
final readonly class Stale
{
	public function __construct(
		public mixed $value,
		public float $freshUntil,
	)
	{
	}
	
	public function isFresh(): bool
	{
		return microtime(true) < $this->freshUntil;
	}
}
