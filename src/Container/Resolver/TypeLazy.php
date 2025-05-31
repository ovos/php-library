<?php
declare(strict_types=1);

namespace Ovos\Container\Resolver;

use Ovos\Container;

/**
 * TypeLazy
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
class TypeLazy extends TypeClass
{
	/**
	 * @param Container $container
	 *
	 * @return ?object
	 */
	public function resolve(Container $container): ?object
	{
		$reflector = $this->getReflector();
		$parameters = $this->getParameters();
		
		$initializer = $this->getInitializer();
		if($initializer === null)
		{
			// will be only called once, we can declare it as static
			$initializer = static function(object $proxy) use ($container, $reflector, $parameters)
			{
				$instanceArgs = $container->resolveConstructor($reflector, $parameters);
				$container->resolveProperties($reflector, $proxy, lazy: true);
				
				return $reflector->newInstanceArgs($instanceArgs);
			};
		}
		
		return $reflector->newLazyProxy($initializer);
	}
}
