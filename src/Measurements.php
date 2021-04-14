<?php
declare(strict_types=1);

namespace Ovos;

use function round;
use function number_format;

/**
 * Measurements
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
class Measurements
{
	/**
	 * Calculates, rounds and formats execution time
	 *
	 * @param float $startTime Execution start time
	 * @param float $endTime Execution end time
	 *
	 * @return string Total time
	 */
	public static function formatTime(float $startTime, float $endTime): string
	{
		$roundedTime = round($endTime - $startTime, 8);
		return number_format($roundedTime, 8, '.', '');
	}

	/**
	 * Calculates and formats memory usage
	 *
	 * @param int $startUsage Execution memory usage
	 * @param int $endUsage Execution memory usage
	 *
	 * @return string Total memory usage
	 */
	public static function formatMemory(int $startUsage, int $endUsage): string
	{
		return Size::format($endUsage - $startUsage);
	}
}
