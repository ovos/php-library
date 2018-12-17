<?php
declare(strict_types=1);

namespace Ovos;

/**
 * Invoker
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
class Invoker
{
	/**
	 * @param callable $callback
	 * @param int $chance
	 *
	 * @return void
	 */
	public static function invokeWithChance(callable $callback, int $chance = 10): void
	{
		if(random_int(1, $chance) === $chance)
		{
			$callback();
		}
	}
}