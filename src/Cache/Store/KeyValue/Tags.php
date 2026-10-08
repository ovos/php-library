<?php
declare(strict_types=1);

namespace Ovos\Cache\Store\KeyValue;

use Ovos\Cache\Store\KeyValue;
use Override;
use Closure;

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
	
	#[Override]
	abstract public function get(
		string $key,
		?Closure $resolver = null,
		int $ttl = 0,
		array $tags = [],
	): mixed;
	
	#[Override]
	public function setFromResolver(
		string $key,
		?Closure $resolver,
		int $ttl = 0,
		array $tags = [],
		int $stale = 0,
		bool $soft = false,
		int $staleIfError = 0,
	): mixed
	{
		if($resolver === null)
		{
			return null;
		}
		
		// the order of arguments is compatible with backends which do not
		// support tags; the resolver may change $ttl, $tags and $save by
		// reference from what it computed (see KeyValue::setFromResolver())
		$save = true;
		$value = $resolver($this, $key, $ttl, $tags, $save);
		
		if($save === false)
		{
			// nothing written: the lock goes now, not at its TTL
			$this->releaseActiveLock($key);
		}
		else if($value !== null)
		{
			[$stored, $storedTtl] = $this->withStale($value, $ttl, $stale, $soft, $staleIfError);
			$this->set($key, $stored, $storedTtl, $tags);
		}
		
		return $value;
	}
	
	#[Override]
	abstract public function set(
		string $key,
		mixed $value,
		int $ttl = 0,
		array $tags = [],
	): bool;
}
