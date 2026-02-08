<?php
declare(strict_types=1);

namespace Ovos;

use function round;
use function number_format;

/**
 * Measurements
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Measurements
{
	/**
	 * Calculates, rounds, and formats execution time
	 */
	public static function formatTime(
		float $startTime,
		float $endTime,
	): string
	{
		$roundedTime = round($endTime - $startTime, 8);
		return number_format($roundedTime, 
			8,
			'.',
			'',
		);
	}
	
	/**
	 * Calculates and formats memory usage
	 */
	public static function formatMemory(
		int $startUsage,
		int $endUsage,
	): string
	{
		return Size::format($endUsage - $startUsage);
	}
}
