<?php
declare(strict_types=1);

namespace Ovos\Cache\Store\KeyValue;

use Ovos\Cache\Store\KeyValue;
use Override;

/**
 * Tags
 *
 * @author Marcin Gil <mg@ovos.at>
 */
abstract class Tags extends KeyValue
{
	// Tag matching modes
	public const string MATCHING_ANY = 'any';
	public const string MATCHING_ALL = 'all';
	
	/**
	 * What setFromResolver() computed, stored with its tags
	 */
	#[Override]
	protected function storeComputed(
		string $key,
		mixed $value,
		int $ttl,
		array $tags,
	): bool
	{
		return $this->set($key, $value, $ttl, $tags);
	}
	
	#[Override]
	abstract public function set(
		string $key,
		mixed $value,
		int $ttl = 0,
		array $tags = [],
	): bool;
	
	/**
	 * Invalidates the values carrying the tags - any of them, or (MATCHING_ALL)
	 * all of them; $hard: a soft value is a miss at once too, not served for
	 * its stale time
	 */
	abstract public function invalidateTags(
		array $tags,
		string $matching = self::MATCHING_ANY,
		bool $hard = false,
	): bool;
}
