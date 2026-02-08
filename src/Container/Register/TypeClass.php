<?php
declare(strict_types=1);

namespace Ovos\Container\Register;

use Ovos\Container\Register;
use Ovos\Container\Traits\TraitInitializer;
use Ovos\Container\Traits\TraitParameters;
use Attribute;

/**
 * TypeClass
 *
 * @author Marcin Gil <mg@ovos.at>
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
class TypeClass extends Register
{
	use TraitParameters;
	use TraitInitializer;
	
	public function __construct(
		array $parameters = [],
		?callable $initializer = null,
	)
	{
		$this->setParameters($parameters);
		$this->setInitializer($initializer);
	}
}
