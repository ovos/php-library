<?php
declare(strict_types=1);

namespace Ovos\Container\Resolver;

use Ovos\Container;
use Ovos\Container\Resolver;

use ReflectionClass;
use Closure;

/**
 * TypeClass
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
class TypeClass extends Resolver
{
	/**
	 * @var string
	 */
	protected string $_class;
	
	/**
	 * @var ?Closure
	 */
	protected ?Closure $_initializer = null;
	
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
		parent::__construct($parameters);
		
		$this->setClass($class);
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
	 * @param ?callable $initializer
	 *
	 * @return self
	 */
	public function setInitializer(?callable $initializer): self
	{
		$this->_initializer = $initializer;
		
		return $this;
	}
	
	/**
	 * @return ?Closure
	 */
	public function getInitializer(): ?Closure
	{
		return $this->_initializer;
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
			$initializer = static function() use ($container, $reflector, $parameters)
			{
				$instanceArgs = $container->resolveConstructor($reflector, $parameters);
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
