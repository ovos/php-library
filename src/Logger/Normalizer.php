<?php
declare(strict_types=1);

namespace Ovos\Logger;

use Ovos\Exception;
use Throwable;

use function count;
use function is_string;
use function sprintf;

/**
 * Normalizes the variadic Logger/Events log() arguments to a single
 * (Throwable, extras) pair every Writer can consume:
 *   - log('message')                    → Exception('message'), []
 *   - log('fmt %s', $arg, …)            → Exception(sprintf(...)), []
 *   - log($throwable)                   → $throwable, []
 *   - log($throwable, ['key' => 'val']) → $throwable, ['key' => 'val']
 *
 * @author Marcin Gil <mg@ovos.at>
 */
final class Normalizer
{
	/**
	 * @return array{0: Throwable, 1: array}|null null when nothing was passed
	 */
	public static function normalize(
		array $event,
	): ?array
	{
		$count = count($event);
		if($count === 0)
		{
			return null;
		}
		
		// string message, optionally with sprintf arguments
		if(is_string($event[0]))
		{
			$message = $count > 1 ? sprintf(...$event) : $event[0];
			
			return [new Exception($message), []];
		}
		
		// a throwable, optionally followed by an extras array
		$extra = $count > 1 ? (array)$event[1] : [];
		
		if($event[0] instanceof Throwable)
		{
			return [$event[0], $extra];
		}
		
		return [new Exception('non-throwable event logged'), $extra];
	}
}
