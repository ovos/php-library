<?php
declare(strict_types=1);

namespace Ovos\Container\Injector;

use Ovos\Container;
use Ovos\Container\Injector;
use Ovos\Container\Traits\TraitParameters;
use Ovos\Container\Traits\TraitInitializer;
use Override;
use ReflectionClass;

/**
 * TypeClass
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class TypeClass extends Injector
{
	use TraitParameters;
	use TraitInitializer;
	
	protected string $class;
	
	protected ?ReflectionClass $reflector = null;
	
	public function __construct(
		string $class,
		array $parameters = [],
		?callable $initializer = null,
	)
	{
		$this->setClass($class);
		$this->setParameters($parameters);
		$this->setInitializer($initializer);
	}
	
	public function setClass(
		string $class,
	): static
	{
		$this->class = $class;
		
		return $this;
	}
	
	public function getClass(): string
	{
		return $this->class;
	}
	
	public function getReflector(): ReflectionClass
	{
		if($this->reflector === null)
		{
			$this->reflector = new ReflectionClass($this->class);
		}
		
		return $this->reflector;
	}
	
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
			$initializer = static function() use ($container, $reflector, $parameters)
			{
				$instanceArgs = $container->injectConstructor($reflector, $parameters);
				$instance = $reflector->newInstanceWithoutConstructor();
				$container->resolveProperties($reflector, $instance);
				
				if($constructor = $reflector->getConstructor())
				{
					$constructor->invokeArgs($instance, $instanceArgs);
				}
				
				return $instance;
			};
		}
		
		return $initializer();
	}
}
