<?php
declare(strict_types=1);

namespace Ovos\Cache;

use function is_array;
use function is_object;
use function serialize;
use function substr;
use function unserialize;

/**
 * Serializer
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Serializer
{
	// Prefixes
	public const string PREFIX_SERIALIZE = "\x01\xe4";
	
	public function serialize(
		mixed $value,
	): mixed
	{
		if($value === null)
		{
			return null;
		}
		
		if(is_array($value) || is_object($value))
		{
			$value = static::PREFIX_SERIALIZE . serialize($value);
		}
		
		return (string)$value;
	}
	
	public function unserialize(
		?string $value,
	): mixed
	{
		if($value === null)
		{
			return null;
		}
		
		$prefix = substr($value, 0, 2);
		if($prefix !== static::PREFIX_SERIALIZE)
		{
			return $value; // not serialized
		}
		$value = substr($value, 2);
		
		return unserialize($value, ['allowed_classes' => true]);
	}
}
