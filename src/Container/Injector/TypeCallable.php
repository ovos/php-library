<?php
declare(strict_types=1);

namespace Ovos\Container\Injector;

use Ovos\Container;
use Ovos\Container\Injector;
use Ovos\Container\Traits\TraitParameters;
use Ovos\Container\Traits\TraitCallable;
use Override;

/**
 * TypeCallable
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class TypeCallable extends Injector
{
	use TraitParameters;
	use TraitCallable;
	
	public function __construct(
		callable $callable,
		array $parameters = [],
	)
	{
		$this->setParameters($parameters);
		$this->setCallable($callable);
	}
	
	#[Override]
	public function inject(
		Container $container,
	): object
	{
		// no resolution whatsoever except for access to the container
		// inside the anonymous function
		return ($this->callable)($container, $this->getParameters());
	}
}
