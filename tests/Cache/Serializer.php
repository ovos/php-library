<?php
declare(strict_types=1);

namespace Tests\Cache;

use Ovos\Cache\Serializer as Subject;
use Ovos\Test;
use stdClass;

use function serialize;
use function str_starts_with;

/**
 * Serializer - what a cache value is stored as. Arrays and objects get the
 * marker and serialize(); a string is stored as it is, so a read must never
 * take a string for serialized data: one that starts like the marker is
 * serialized as well, or a cached string an outsider wrote would come back
 * as objects of their choosing
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Serializer extends Test
{
	public function aStringThatStartsWithTheMarkerReadsBackAsItself(): bool
	{
		$serializer = new Subject;
		$value = Subject::PREFIX_SERIALIZE . serialize(new stdClass);
		
		return $serializer->unserialize($serializer->serialize($value)) === $value;
	}
	
	public function everyStringOnTheMarkersLeadByteReadsBackAsItself(): bool
	{
		$serializer = new Subject;
		
		foreach(["\x01", "\x01\x00", "\x01\xe5abcdefghijkl", "\x01\xe6" . serialize([1])] as $value)
		{
			if($serializer->unserialize($serializer->serialize($value)) !== $value)
			{
				return false;
			}
		}
		
		return true;
	}
	
	public function aPlainStringIsStoredAsItIs(): bool
	{
		$serializer = new Subject;
		
		return $serializer->serialize('plain') === 'plain'
			&& $serializer->serialize('') === ''
			&& $serializer->unserialize('plain') === 'plain';
	}
	
	public function anArrayIsStoredSerializedBehindTheMarker(): bool
	{
		$serializer = new Subject;
		$stored = $serializer->serialize(['a' => 1]);
		
		return str_starts_with($stored, Subject::PREFIX_SERIALIZE)
			&& $serializer->unserialize($stored) === ['a' => 1];
	}
}
