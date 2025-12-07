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
	
	protected object $object;
	
	protected ?ReflectionObject $reflector = null;
	
	public function __construct(
		object $object,
		?callable $initializer = null,
	)
	{
		$this->setObject($object);
		$this->setInitializer($initializer);
	}
	
	public function setObject(
		object $object,
	): static
	{
		$this->object = $object;
		
		return $this;
	}
	
	public function getObject(): object
	{
		return $this->object;
	}
	
	public function getReflector(): ReflectionObject
	{
		if($this->reflector === null)
		{
			$this->reflector = new ReflectionObject($this->object);
		}
		
		return $this->reflector;
	}
	
	#[Override]
	public function inject(
		Container $container,
	): object
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
