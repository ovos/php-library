<?php
declare(strict_types=1);

namespace Ovos;

use function random_int;

/**
 * Invoker
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
class Invoker
{
	public static function invokeWithChance(
		callable $callback,
		int $chance = 10,
	): void
	{
		if(random_int(1, $chance) === $chance)
		{
			$callback();
		}
	}
}
