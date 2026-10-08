<?php
declare(strict_types=1);

namespace Ovos\Cache;

use function is_array;
use function is_object;
use function is_string;
use function serialize;
use function str_starts_with;
use function substr;
use function unserialize;

/**
 * Serializer
 *
 * What a cache value is stored as: an array or an object behind
 * PREFIX_SERIALIZE, anything else as a string. The marker is in-band, so a
 * string that starts with its lead byte is serialized as well - otherwise a
 * cached string shaped like serialized data, one an outsider wrote among
 * them, would be read back as the objects it describes. Only this class's
 * own output starts with the marker; a reader before the escape gets the
 * same string back
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Serializer
{
	// Prefixes
	public const string PREFIX_SERIALIZE = "\x01\xe4";
	
	/**
	 * The marker's lead byte: a string that starts with it is escaped, so
	 * only this class's own output starts like the marker
	 */
	public const string MARKER_LEAD = "\x01";
	
	public function serialize(
		mixed $value,
	): mixed
	{
		if($value === null)
		{
			return null;
		}
		
		if(is_array($value)
			|| is_object($value)
			|| (is_string($value) && str_starts_with($value, static::MARKER_LEAD)))
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
