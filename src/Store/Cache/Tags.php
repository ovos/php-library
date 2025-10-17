<?php
declare(strict_types=1);

namespace Ovos\Store\Cache;

use Ovos\Store\Cache;

/**
 * Cache
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
abstract class Tags extends Cache
{
	/**
	 * @param string $key
	 * @param ?SetCallback $set
	 *
	 * @return null|mixed
	 */
	abstract public function get(
		string $key,
		?SetCallback $set = null,
	): mixed;
	
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
