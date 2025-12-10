<?php
declare(strict_types=1);

namespace Ovos\Container\Traits;

use Closure;

/**
 * TraitCallable
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
trait TraitCallable
{
	protected Closure $callable;
	
	public function setCallable(
		callable $callable,
	): static
	{
		$this->callable = $callable;
		
		return $this;
	}
	
	public function getCallable(): Closure
	{
		return $this->callable;
	}
}
