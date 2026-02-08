<?php
declare(strict_types=1);

namespace Ovos;

use Closure;

use function random_int;

/**
 * Invoker
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Invoker
{
	protected ?object $context = null;
	
	public function __construct(
		?object $context = null,
	)
	{
		$this->context = $context;
	}
	
	public function invoke(
		?Closure $function = null,
	): mixed
	{
		if($function === null)
		{
			return null;
		}
		
		return $function($this->context);
	}
	
	public function invokeWithChance(
		Closure $function,
		int $chance = 10,
	): void
	{
		if(random_int(1, $chance) === $chance)
		{
			$function($this->context);
		}
	}
}
