<?php
declare(strict_types=1);

namespace Ovos\Container\Injector;

use Ovos\Container;
use Ovos\Container\Injector;
use Ovos\Container\Traits\TraitInitializer;
use Override;
use ReflectionObject;

/**
 * TypeObject
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
class TypeObject extends Injector
{
	use TraitInitializer;
	
	/**
	 * @var object
	 */
	protected object $_object;
	
	/**
	 * @var ?ReflectionObject 
	 */
	protected ?ReflectionObject $_reflector = null;
	
	/**
	 * @param object $object
	 * @param ?callable $initializer
	 */
	public function __construct(object $object,
		?callable $initializer = null,
	)
	{
		$this->setObject($object);
		$this->setInitializer($initializer);
	}
	
	/**
	 * @param object $object
	 *
	 * @return static
	 */
	public function setObject(object $object): static
	{
		$this->_object = $object;
		
		return $this;
	}
	
	/**
	 * @return object
	 */
	public function getObject(): object
	{
		return $this->_object;
	}
	
	/**
	 * @return ReflectionObject
	 */
	public function getReflector(): ReflectionObject
	{
		if($this->_reflector === null)
		{
			$this->_reflector = new ReflectionObject($this->_object);
		}
		
		return $this->_reflector;
	}
	
	/**
	 * @param Container $container
	 *
	 * @return object
	 */
	#[Override]
	public function inject(Container $container): object
	{
		$reflector = $this->getReflector();
		$object = $this->getObject();
		
		$initializer = $this->getInitializer();
		if($initializer === null)
		{
			// will be only called once, we can declare it as static
			$initializer = static function() use ($container, $reflector, $object)
			{
				$container->resolveProperties($reflector, $object);
				
				return $object;
			};
		}
		
		return $initializer();
	}
}
