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
	): mixed
	{
		if($resolver === null)
		{
			return null;
		}
		
		// the order of arguments is compatible with backends
		// which do not support tags
		$value = $resolver($this, $key, $ttl, $tags);
		
		if($value !== null)
		{
			$this->set($key, $value, $ttl, $tags);
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
