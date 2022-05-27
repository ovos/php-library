<?php
declare(strict_types=1);

namespace Ovos;

use function log;
use function count;
use function round;
use function min;
use function max;
use function floor;

/**
 * Size
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
class Size
{
	/**
	 * Get size expressed in B, KB, MB, GB...
	 *
	 * @param int $bytes
	 * @param int $precision
	 *
	 * @return string
	 */
	public static function format(int $bytes, int $precision = 2): string
	{
		$units = ['B', 'KB', 'MB', 'GB', 'TB']; 
	
		$bytes = max($bytes, 0);
		$pow = floor(($bytes ? log($bytes) : 0) / log(1024)); 
		$pow = min($pow, count($units) - 1);
		$bytes /= 1024 ** $pow;
	
		return round($bytes, $precision) . ' ' . $units[$pow]; 
	}
}
