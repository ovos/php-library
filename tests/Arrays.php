<?php
declare(strict_types=1);

namespace Tests;

use Ovos\Test;
use Ovos\Arrays as BaseArrays;

/**
 * Arrays
 *
 * @package Tests
 * @author Marcin Gil <mg@ovos.at>
 */
class Arrays extends Test
{
	public function deepMerge(): bool
	{
		$array = [
			[0, 1, 2],
			[0, 1, 2],
		];
		
		$merge = [
			[3, 4, 5],
			[3, 4, 5],
		];
		
		$result = [
			[0, 1, 2, 3, 4, 5],
			[0, 1, 2, 3, 4, 5],
		];
		
		$merged = BaseArrays::deepMerge($array, $merge);
		
		return $merged === $result;
	}
}
