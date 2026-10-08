<?php
declare(strict_types=1);

namespace Tests\Cache;

use Ovos\Cache\Serializer as Subject;
use Ovos\Cache\Stale;
use Ovos\Test;
use stdClass;

use function serialize;
use function str_replace;
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
	
	/**
	 * A serialized payload that does not decode is no value - a miss, never
	 * false - and so is an object of a class that is gone (a rolling deploy
	 * renamed it), on its own or inside a Stale record
	 */
	public function aPayloadThatDoesNotDecodeIsNoValue(): bool
	{
		$serializer = new Subject;
		$gone = 'O:18:"NoSuchClassAnyMore":0:{}';
		$stale = serialize(new Stale(['kept'], 1.5, 30));
		$staleGone = str_replace('a:1:{i:0;s:4:"kept";}', $gone, $stale);
		
		// false needs no warning: this class never serializes a bare boolean
		return $serializer->unserialize(Subject::PREFIX_SERIALIZE . 'b:0;') === null
			&& $serializer->unserialize(Subject::PREFIX_SERIALIZE . 'a:1:{garbage') === null
			&& $serializer->unserialize(Subject::PREFIX_SERIALIZE . $gone) === null
			&& $staleGone !== $stale
			&& $serializer->unserialize(Subject::PREFIX_SERIALIZE . $staleGone) === null
			&& $serializer->unserialize(Subject::PREFIX_SERIALIZE . $stale) instanceof Stale;
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
