<?php
declare(strict_types=1);

namespace Ovos\Container\Resolver;

use Ovos\Container;
use Ovos\Container\Resolver;

use Closure;

/**
 * TypeCallable
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
class TypeCallable extends Resolver
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
	
	/**
	 * @param Container $container
	 *
	 * @return object
	 */
	public function resolve(Container $container): object
	{
		// no resolution whatsoever except for access to the container
		// inside the anonymous function
		return ($this->_callable)($container, $this->getParameters());
	}
}
