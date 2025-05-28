<?php
declare(strict_types=1);

namespace Ovos;

use Ovos\Container\Entry;
use Ovos\Container\Inject;
use Ovos\Container\Injected;

use ReflectionAttribute;
use ReflectionClass;
use ReflectionParameter;
use ReflectionType;
use ReflectionNamedType;
use ReflectionUnionType;
use ReflectionProperty;

use function array_map;
use function count;

/**
 * Container
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
class Container
{
	/**
	 * @var Entry[]
	 */
	protected array $_entries = [];
	
	/**
	 * @var object[]
	 */
	protected array $_resolved = [];
	
	/**
	 * @var ReflectionClass[]
	 */
	protected array $_reflectors = [];
	
	/**
	 * Register class
	 * 
	 * @param string $key
	 * @param string $class
	 * @param array $parameters
	 * @param ?callable $initializer
	 * 
	 * @return self
	 */
	public function registerClass(string $key,
		string $class,
		array $parameters = [],
		?callable $initializer = null,
	): self
	{
		$this->_entries[$key] = new Entry\TypeClass($class,
			$parameters,
			$initializer,
		);
		
		return $this;
	}
	
	/**
	 * Register a lazy object
	 * 
	 * @param string $key
	 * @param string $class
	 * @param array $parameters
	 * @param ?callable $initializer
	 * 
	 * @return self
	 */
	public function registerLazy(string $key,
		string $class,
		array $parameters = [],
		?callable $initializer = null,
	): self
	{
		// compatibility with pre 8.4
		if(PHP_VERSION_ID < 84000)
		{
			return $this->registerClass($key,
				$class,
				$parameters,
				$initializer,
			);
		}
		
		$this->_entries[$key] = new Entry\TypeLazy($class,
			$parameters,
			$initializer,
		);
		
		return $this;
	}
	
	/**
	 * Register a callable and instantiate it on demand
	 * 
	 * @param string $key
	 * @param callable $callable
	 * @param array $parameters
	 * 
	 * @return self
	 */
	public function registerCallable(string $key,
		callable $callable,
		array $parameters = [],
	): self
	{
		$this->_entries[$key] = new Entry\TypeCallable($callable,
			$parameters,
		);
		
		return $this;
	}
	
	/**
	 * Register an instance of an object
	 * No need to resolve dependencies
	 * 
	 * @param string $key
	 * @param object $object
	 * 
	 * @return self
	 */
	public function registerObject(string $key, object $object): self
	{
		$this->_resolved[$key] = $object;
		
		return $this;
	}
	
	/**
	 * Register an instance or a lazy object
	 * No need to resolve dependencies
	 * 
	 * @param string $key
	 * @param mixed $value
	 * 
	 * @return self
	 */
	public function registerValue(string $key, mixed $value): self
	{
		$this->_resolved[$key] = $value;
		
		return $this;
	}
	
	/**
	 * Returns a resolved object or value
	 * 
	 * @param string $key
	 * 
	 * @return ?object
	 */
	public function get(string $key): ?object
	{
		if(isset($this->_resolved[$key]))
		{
			return $this->_resolved[$key];
		}
		if(isset($this->_entries[$key])
			&& ($resolved = $this->resolve($this->_entries[$key])) !== null
		)
		{
			$this->_resolved[$key] = $resolved;
		}
		
		return $this->_resolved[$key] ?? null;
	}
	
	/**
	 * Resolves dependencies in constructor
	 * or marked with #[Inject] attribute
	 * 
	 * @param Entry $entry
	 * 
	 * @return ?object
	 */
	public function resolve(Entry $entry): ?object
	{
		if($entry instanceof Entry\TypeLazy)
		{
			return $entry->resolve($this);
		}
		if($entry instanceof Entry\TypeClass)
		{
			return $entry->resolve($this);
		}
		if($entry instanceof Entry\TypeCallable)
		{
			return $entry->resolve($this);
		}
		
		return null;
	}
	
	/**
	 * @param ReflectionClass $reflector
	 * @param array $values
	 * @return array
	 */
	public function resolveConstructor(ReflectionClass $reflector,
		array $values = [],
	): array
	{
		if(($constructor = $reflector->getConstructor()) === null)
		{
			return [];
		}
		
		$parameters = $constructor->getParameters();
		
		return array_map
		(
			function(ReflectionParameter $parameter) use ($values)
			{
				$resolved = $this->_resolveValueByName($parameter->getName(),
					$values)
					?? $this->_resolveValueByKey($parameter)
					?? $this->_resolveValueByType($parameter->getType());
				
				if($resolved !== null)
				{
					return $this->_processAttributes($parameter, $resolved);
				}
				
				return null;
			},
			$parameters
		);
	}
	
	/**
	 * Match parameters by name (and return its value if found)
	 * 
	 * @param string $parameterName
	 * @param array $parameters
	 * 
	 * @return mixed
	 */
	protected function _resolveValueByName(string $parameterName,
		array $parameters): mixed
	{
		if(array_key_exists($parameterName, $parameters) !== false)
		{
			return $parameters[$parameterName];
		}
		
		return null;
	}
	
	/**
	 * @param ReflectionProperty|ReflectionParameter $property
	 *
	 * @return mixed
	 */
	protected function _resolveValueByKey(
		ReflectionProperty|ReflectionParameter $property,
	): mixed
	{
		$attributes = $property->getAttributes(Inject::class);
		foreach($attributes as $attribute)
		{
			$arguments = $attribute->getArguments();
			if(count($arguments) === 0)
			{
				continue;
			}
			
			$key = $arguments[0]; // does not have to be a type, may be also just a key
			if(($object = $this->get($key)) !== null)
			{
				return $object;
			}
		}
		
		return null;
	}
	
	/**
	 * Match parameters by type (and resolve them)
	 * 
	 * @param ?ReflectionType $parameterType
	 * 
	 * @return mixed
	 */
	protected function _resolveValueByType(?ReflectionType $parameterType,
	): mixed
	{
		if($parameterType === null)
		{
			return null;
		}
		
		$types = [];
		if($parameterType instanceof ReflectionUnionType)
		{
			foreach($parameterType->getTypes() as $type)
			{
				if($type->isBuiltin()) // also null values
				{
					continue;
				}
				
				$types[] = $type->getName();
			}
		}
		else if($parameterType instanceof ReflectionNamedType
			|| $parameterType->isBuiltin() === false)
		{
			$types[] = $parameterType->getName();
		}
		
		foreach($types as $type)
		{
			// take the first one that we could resolve
			if(($object = $this->get($type)) !== null)
			{
				return $object;
			}
		}
		
		return null;
	}
	
	/**
	 * @param ReflectionClass $reflector
	 * @param object $object
	 * @param bool $lazy
	 * 
	 * @return void
	 */
	public function resolveProperties(ReflectionClass $reflector,
		object $object,
		bool $lazy = false,
	): void
	{
		foreach($reflector->getProperties() as $property)
		{
			$attributesInject = $property->getAttributes(Container\Inject::class);
			if(count($attributesInject) === 0)
			{
				continue;
			}
			
			$resolved = $this->_resolveValueByKey($property)
				?? $this->_resolveValueByType($property->getType());
			
			if($resolved !== null)
			{
				$resolved = $this->_processAttributes($property, $resolved);
				$this->_inject($property, $object, $resolved, $lazy);
			}
		}
	}
	
	/**
	 * @param ReflectionProperty|ReflectionParameter $property
	 * @param mixed $resolved
	 * 
	 * @return mixed
	 */
	protected function _processAttributes(
		ReflectionProperty|ReflectionParameter $property,
		mixed $resolved,
	): mixed
	{
		$attributes = $property->getAttributes(Injected::class,
			ReflectionAttribute::IS_INSTANCEOF);
		foreach($attributes as $attribute)
		{
			$instance = $attribute->newInstance();
			$resolved = $instance->process($resolved);
		}
		
		return $resolved;
	}
	
	/**
	 * @param ReflectionProperty $property
	 * @param object $object
	 * @param mixed $resolved
	 * @param bool $lazy
	 * 
	 * @return void
	 */
	protected function _inject(ReflectionProperty $property,
		object $object,
		mixed $resolved,
		bool $lazy,
	): void
	{
		if($lazy)
		{
			$property->setRawValueWithoutLazyInitialization($object, $resolved);
		}
		else
		{
			// compatibility with pre 8.4
			if(PHP_VERSION_ID < 84000)
			{
				$property->setValue($object, $resolved);
			}
			else
			{
				$property->setRawValue($object, $resolved);
			}
		}
	}
	
	/**
	 * Check if a given key is registered
	 * 
	 * @param string $key
	 * 
	 * @return bool
	 */
	public function isRegistered(string $key): bool
	{
		return isset($this->_entries[$key]);
	}
}
