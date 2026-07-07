<?php
declare(strict_types=1);

namespace Tests\Cache;

use Ovos\Cache\Holes as Subject;
use Ovos\Exception;
use Ovos\Test;

/**
 * Holes - the sentinel/segment machinery behind cached-page holes
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Holes extends Test
{
	public function placeholderWrapsTheNameInSentinels(): bool
	{
		$holes = new Subject;
		
		return $holes->placeholder('csrf') === "\x1Ecsrf\x1E"
			&& $holes->contains("a\x1Ecsrf\x1Eb") === true
			&& $holes->contains('plain html') === false;
	}
	
	public function invalidNamesThrow(): bool
	{
		$holes = new Subject;
		
		try
		{
			$holes->placeholder('white space');
		}
		catch(Exception)
		{
			return true;
		}
		
		return false;
	}
	
	public function splitInterleavesSegmentsAndHoles(): bool
	{
		$holes = new Subject;
		$body = 'A' . $holes->placeholder('x') . 'B'
			. $holes->placeholder('y') . 'C';
			
		$split = $holes->split($body);
		
		return $split['segments'] === ['A', 'B', 'C']
			&& $split['holes'] === ['x', 'y'];
	}
	
	public function splitHandlesEdgeAndAdjacentSentinels(): bool
	{
		$holes = new Subject;
		
		// a body that IS one hole: empty segments on both sides
		$alone = $holes->split($holes->placeholder('a'));
		
		// adjacent holes: an empty segment between them
		$adjacent = $holes->split(
			'A' . $holes->placeholder('a') . $holes->placeholder('b') . 'B');
			
		return $alone['segments'] === ['', '']
			&& $alone['holes'] === ['a']
			&& $adjacent['segments'] === ['A', '', 'B']
			&& $adjacent['holes'] === ['a', 'b'];
	}
	
	public function assembleResolvesInDocumentOrder(): bool
	{
		$holes = new Subject;
		$holes->provide('x', static fn(): string => '1');
		$holes->provide('y', static fn(): string => '2');
		
		$body = 'A' . $holes->placeholder('x') . 'B'
			. $holes->placeholder('y') . 'C';
		$split = $holes->split($body);
		
		return $holes->assemble($split['segments'], $split['holes']) === 'A1B2C'
			&& $holes->fill($body) === 'A1B2C'
			&& $holes->fill('plain html') === 'plain html';
	}
	
	public function resolveMemoizesPerRequest(): bool
	{
		$holes = new Subject;
		$calls = 0;
		$holes->provide('once', static function() use (&$calls): string
		{
			$calls++;
			
			return 'value';
		});
		
		$first = $holes->resolve('once');
		$second = $holes->resolve('once');
		
		return $first === 'value'
			&& $second === 'value'
			&& $calls === 1;
	}
	
	public function unprovidedHolesResolveEmptyAndAreTracked(): bool
	{
		$holes = new Subject;
		$holes->provide('known', static fn(): string => 'v');
		
		return $holes->resolve('unknown') === ''
			&& $holes->missed() === ['unknown']
			&& $holes->canResolveAll(['known']) === true
			&& $holes->canResolveAll(['known', 'unknown']) === false;
	}
	
	public function capturingFlagRoundTrips(): bool
	{
		$holes = new Subject;
		
		$before = $holes->isCapturing();
		$holes->capturing(true);
		$during = $holes->isCapturing();
		$holes->capturing(false);
		
		return $before === false
			&& $during === true
			&& $holes->isCapturing() === false;
	}
}
