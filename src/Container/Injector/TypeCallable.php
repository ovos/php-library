<?php
declare(strict_types=1);

namespace Ovos\Container\Injector;

use Ovos\Container;
use Ovos\Container\Injector;
use Ovos\Container\Traits\TraitParameters;
use Ovos\Container\Traits\TraitCallable;

/**
 * TypeCallable
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
class TypeCallable extends Injector
{
	use TraitParameters;
	use TraitCallable;
	
	/**
	 * @param callable $callable
	 * @param array $parameters
	 */
	public function __construct(callable $callable,
		array $parameters = [],
	)
	{
		$this->setParameters($parameters);
		$this->setCallable($callable);
	}
	
	/**
	 * @param Container $container
	 *
	 * @return object
	 */
	public function inject(Container $container): object
	{
		// no resolution whatsoever except for access to the container
		// inside the anonymous function
		return ($this->_callable)($container, $this->getParameters());
	}
}
