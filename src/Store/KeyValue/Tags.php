<?php
declare(strict_types=1);

namespace Ovos\Store\KeyValue;

use Ovos\Store\KeyValue;
use Override;
use Closure;

/**
 * Tags
 *
 * @package Ovos
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
		
		// order of arguments is determined by the compatibility
		// with backends with no tags
		$value = $resolver($this, $key, $ttl, $tags);
		$this->set($key, $value, $ttl, $tags);
		
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
