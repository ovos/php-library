<?php
declare(strict_types=1);

namespace Ovos\Container\Injector;

use Ovos\Container;
use Override;

/**
 * TypeLazy
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class TypeLazy extends TypeClass
{
	#[Override]
	public function inject(
		Container $container,
	): object
	{
		$reflector = $this->getReflector();
		$parameters = $this->getParameters();
		
		$initializer = $this->getInitializer();
		if($initializer === null)
		{
			// will be only called once, we can declare it as static
			$initializer = static function(object $proxy) use ($container, $reflector, $parameters)
			{
				$instanceArgs = $container->injectConstructor($reflector, $parameters);
				$container->resolveProperties($reflector, $proxy, lazy: true);
				
				return $reflector->newInstanceArgs($instanceArgs);
			};
		}
		
		return $reflector->newLazyProxy($initializer);
	}
}
