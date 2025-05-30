<?php
declare(strict_types=1);

namespace Ovos\Container\Register;

use Ovos\Container\Register;
use Attribute;
use Closure;

/**
 * TypeCallable
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
class TypeCallable extends Register
{
	/**
	 * @var Closure
	 */
	protected Closure $_callable;
	
	/**
	 * @param callable $callable
	 * @param array $parameters
	 */
	public function __construct(callable $callable,
		array $parameters = [],
	)
	{
		parent::__construct($parameters);
		
		$this->setCallable($callable);
	}
	
	/**
	 * @param callable $callable
	 *
	 * @return self
	 */
	public function setCallable(callable $callable): self
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
