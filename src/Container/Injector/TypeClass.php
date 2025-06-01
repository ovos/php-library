<?php
declare(strict_types=1);

namespace Ovos\Container\Injector;

use Ovos\Container;
use Ovos\Container\Injector;
use Ovos\Container\Traits\TraitParameters;
use Ovos\Container\Traits\TraitInitializer;

use ReflectionClass;

/**
 * TypeClass
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
class TypeClass extends Injector
{
	use TraitParameters;
	use TraitInitializer;
	
	/**
	 * @var string
	 */
	protected string $_class;
	
	/**
	 * @var ?ReflectionClass 
	 */
	protected ?ReflectionClass $_reflector = null;
	
	/**
	 * @param string $class
	 * @param array $parameters
	 * @param ?callable $initializer
	 */
	public function __construct(string $class,
		array $parameters = [],
		?callable $initializer = null,
	)
	{
		$this->setClass($class);
		$this->setParameters($parameters);
		$this->setInitializer($initializer);
	}
	
	/**
	 * @param string $class
	 *
	 * @return self
	 */
	public function setClass(string $class): self
	{
		$this->_class = $class;
		
		return $this;
	}
	
	/**
	 * @return string
	 */
	public function getClass(): string
	{
		return $this->_class;
	}
	
	/**
	 * @return ReflectionClass
	 */
	public function getReflector(): ReflectionClass
	{
		if($this->_reflector === null)
		{
			$this->_reflector = new ReflectionClass($this->_class);
		}
		
		return $this->_reflector;
	}
	
	/**
	 * @param Container $container
	 *
	 * @return object
	 */
	public function inject(Container $container): object
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
