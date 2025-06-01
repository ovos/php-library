<?php
declare(strict_types=1);

namespace Ovos\Container\Register;

use Ovos\Container\Register;
use Ovos\Container\Traits\TraitCallable;
use Ovos\Container\Traits\TraitParameters;
use Attribute;

/**
 * TypeCallable
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
class TypeCallable extends Register
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
}
