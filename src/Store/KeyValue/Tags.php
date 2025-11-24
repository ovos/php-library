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
	/**
	 * @param string $key
	 * @param ?Closure $resolver
	 * @param int $ttl
	 * @param array $tags
	 *
	 * @return null|mixed
	 */
	#[Override]
	abstract public function get(
		string $key,
		?Closure $resolver = null,
		int $ttl = 0,
		array $tags = [],
	): mixed;
	
	/**
	 * @param string $key
	 * @param ?Closure $resolver
	 * @param int $ttl
	 * @param array $tags
	 *
	 * @return mixed
	 */
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
		
		$value = $resolver($this, $key, $ttl, $tags);
		$this->set($key, $value, $ttl, $tags);
		
		return $value;
	}
	
	/**
	 * @param string $key
	 * @param mixed $value
	 * @param int $ttl
	 * @param array $tags
	 *
	 * @return bool
	 */
	#[Override]
	abstract public function set(
		string $key,
		mixed $value,
		int $ttl = 0,
		array $tags = [],
	): bool;
}
