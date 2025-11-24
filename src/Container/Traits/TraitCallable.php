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
	/**
	 * @var Closure
	 */
	protected Closure $_callable;
	
	/**
	 * @param callable $callable
	 *
	 * @return static
	 */
	public function setCallable(callable $callable): static
	{
		$this->_callable = $callable;
		
		return $this;
	}
	
	/**
	 * @return Closure
	 */
	public function getCallable(): Closure
	{
		return $this->_callable;
	}
}
