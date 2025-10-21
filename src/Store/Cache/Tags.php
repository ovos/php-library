<?php
declare(strict_types=1);

namespace Ovos\Store\Cache;

use Ovos\Store\Cache;
use Closure;

/**
 * Tags
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
abstract class Tags extends Cache
{
	/**
	 * @param string $key
	 * @param ?Closure $setCallback
	 * @param int $ttl
	 * @param array $tags
	 *
	 * @return null|mixed
	 */
	abstract public function get(
		string $key,
		?Closure $setCallback = null,
		int $ttl = 0,
		array $tags = [],
	): mixed;
	
	/**
	 * @param string $key
	 * @param ?Closure $setCallback
	 * @param int $ttl
	 * @param array $tags
	 *
	 * @return mixed
	 */
	public function setFromCallback(
		string $key,
		?Closure $setCallback,
		int $ttl = 0,
		array $tags = [],
	): mixed
	{
		if($setCallback === null)
		{
			return null;
		}
		
		$value = $setCallback($this);
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
	abstract public function set(
		string $key,
		mixed $value,
		int $ttl = 0,
		array $tags = [],
	): bool;
}
