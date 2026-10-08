<?php
declare(strict_types=1);

namespace Ovos\Cache;

/**
 * Computed
 *
 * What a resolver may return instead of the bare value, to decide from what
 * it computed how the value is kept: its own TTL, its own tags (a store with
 * tags), or nothing to store at all - the value is handed back, nothing is
 * written, and the lock is freed at once, so the next caller does not wait
 * for its TTL. A resolver is called as function(KeyValue $store,
 * string $key); a bare value is stored as the get() call says
 *
 * @author Marcin Gil <mg@ovos.at>
 */
final readonly class Computed
{
	/**
	 * @param ?int $ttl the TTL to store it with (null: the call's)
	 * @param ?array $tags the tags to store it with (null: the call's)
	 * @param bool $save false: handed back, nothing stored, the lock freed
	 */
	public function __construct(
		public mixed $value,
		public ?int $ttl = null,
		public ?array $tags = null,
		public bool $save = true,
	)
	{
	}
}
