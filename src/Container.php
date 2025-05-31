<?php
declare(strict_types=1);

namespace Ovos;

use Ovos\Container\Resolver;
use Ovos\Container\Inject;
use Ovos\Container\Injected;
use Ovos\Container\Register;
use Ovos\Container\Register\TypeClass;
use Ovos\Container\Register\TypeLazy;
use Ovos\Container\Register\TypeCallable;

use ReflectionAttribute;
use ReflectionClass;
use ReflectionParameter;
use ReflectionType;
use ReflectionNamedType;
use ReflectionUnionType;
use ReflectionProperty;

use function array_map;
use function array_keys;
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
	 * @var Resolver[]
	 */
	protected array $_resolvers = [];
	
	/**
	 * @var object[]
	 */
	protected array $_resolved = [];
	
	/**
	 * @var ReflectionClass[]
	 */
	protected array $_reflectors = [];
	
	/**
	 * Register a class
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
		if(isset($this->_resolvers[$key])) // already registered
		{
			return $this;
		}
		
		$this->_resolvers[$key] = new Resolver\TypeClass($class,
			$parameters,
			$initializer,
		);
		
		return $this;
	}
	
	/**
	 * Get a class
	 *
	 * @param string $key
	 * @param string $class
	 * @param array $parameters
	 * @param ?callable $initializer
	 *
	 * @return ?object
	 */
	public function getClass(string $key,
		string $class,
		array $parameters = [],
		?callable $initializer = null,
	): ?object
	{
		if($this->isRegistered($key) === false)
		{
			$this->registerClass($key, $class, $parameters, $initializer);
		}
		
		return $this->get($key);
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
		if(isset($this->_resolvers[$key])) // already registered
		{
			return $this;
		}
		
		// compatibility with pre 8.4
		if(PHP_VERSION_ID < 84000)
		{
			return $this->registerClass($key,
				$class,
				$parameters,
				$initializer,
			);
		}
		
		$this->_resolvers[$key] = new Resolver\TypeLazy($class,
			$parameters,
			$initializer,
		);
		
		return $this;
	}
	
	/**
	 * Get a lazy object
	 *
	 * @param string $key
	 * @param string $class
	 * @param array $parameters
	 * @param ?callable $initializer
	 *
	 * @return ?object
	 */
	public function getLazy(string $key,
		string $class,
		array $parameters = [],
		?callable $initializer = null,
	): ?object
	{
		if($this->isRegistered($key) === false)
		{
			$this->registerLazy($key, $class, $parameters, $initializer);
		}
		
		return $this->get($key);
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
		if(isset($this->_resolvers[$key])) // already registered
		{
			return $this;
		}
		
		$this->_resolvers[$key] = new Resolver\TypeCallable($callable,
			$parameters,
		);
		
		return $this;
	}
	
	/**
	 * Get a callable
	 *
	 * @param string $key
	 * @param callable $callable
	 * @param array $parameters
	 *
	 * @return ?object
	 */
	public function getCallable(string $key,
		callable $callable,
		array $parameters = [],
	): ?object
	{
		if($this->isRegistered($key) === false)
		{
			$this->registerCallable($key, $callable, $parameters);
		}
		
		return $this->get($key);
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
		if(isset($this->_resolved[$key])) // already registered
		{
			return $this;
		}
		
		$this->_resolved[$key] = $object;
		
		return $this;
	}
	
	/**
	 * Get an object
	 *
	 * @param string $key
	 * @param object $object
	 *
	 * @return ?object
	 */
	public function getObject(string $key, object $object): ?object
	{
		if($this->isRegistered($key) === false)
		{
			$this->registerObject($key, $object);
		}
		
		return $this->get($key);
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
		if(isset($this->_resolved[$key])) // already registered
		{
			return $this;
		}
		
		$this->_resolved[$key] = $value;
		
		return $this;
	}
	
	/**
	 * Get a value
	 *
	 * @param string $key
	 * @param mixed $value
	 *
	 * @return mixed
	 */
	public function getValue(string $key, mixed $value): mixed
	{
		if($this->isRegistered($key) === false)
		{
			$this->registerValue($key, $value);
		}
		
		return $this->get($key);
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
		if(isset($this->_resolvers[$key])
			&& $this->_resolvers[$key] instanceof Resolver
			&& ($resolved = $this->resolve($this->_resolvers[$key])) !== null
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
	 * @param Resolver $resolver
	 *
	 * @return ?object
	 */
	public function resolve(Resolver $resolver): ?object
	{
		if($resolver instanceof Resolver\TypeLazy)
		{
			return $resolver->resolve($this);
		}
		if($resolver instanceof Resolver\TypeClass)
		{
			return $resolver->resolve($this);
		}
		if($resolver instanceof Resolver\TypeCallable)
		{
			return $resolver->resolve($this);
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
					?? $this->_resolveValueByType($parameter);
				
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
		if(count($attributes) === 0)
		{
			return null;
		}
		
		$arguments = $attributes[0]->getArguments();
		if(count($arguments) === 0)
		{
			return null;
		}
		
		$object = $this->_resolveTypes($arguments);
		
		// return an object if we managed to resolve it
		if($object !== null)
		{
			return $object;
		}
		
		// could not be resolved,
		// try to autoregister with the key
		return $this->_resolveValueByType($property, $arguments[0]);
	}
	
	/**
	 * Match parameters by type (and resolve them)
	 *
	 * @param ReflectionProperty|ReflectionParameter $property
	 * @param ?string $key
	 *
	 * @return mixed
	 */
	protected function _resolveValueByType(
		ReflectionProperty|ReflectionParameter $property,
		?string $key = null,
	): mixed
	{
		$propertyType = $property->getType();
		if($propertyType === null)
		{
			return null;
		}
		
		$types = $this->_getOwnTypes($propertyType);
		if(count($types) === 0)
		{
			return null;
		}
		
		$object = $this->_resolveTypes($types);
		
		// return an object if we managed to resolve it
		if($object !== null)
		{
			return $object;
		}
		
		$key = $key ?? $types[0];
		
		// could not be resolved,
		// try to autoregister with the first type
		return $this->_register($property, $key, $types[0]);
	}
	
	/**
	 * @param array $types
	 *
	 * @return ?object
	 */
	protected function _resolveTypes(
		array $types,
	): ?object
	{
		if(count($types) === 0)
		{
			return null;
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
	 * Try to automatically register the resolver
	 * 
	 * @param ReflectionProperty|ReflectionParameter $property
	 * @param string $key
	 * @param string $type
	 *
	 * @return ?object
	 */
	public function _register(
		ReflectionProperty|ReflectionParameter $property,
		string $key,
		string $type,
	): ?object
	{
		$attributes = $property->getAttributes(Register::class,
			ReflectionAttribute::IS_INSTANCEOF);
		if(count($attributes) === 0)
		{
			$this->registerClass($key, $type);
			return $this->get($key);
		}
		
		$attribute = $attributes[0];
		$attributeName = $attribute->getName();
		
		if($attributeName === TypeClass::class)
		{
			$instance = $attribute->newInstance();
			$this->registerClass($key, $type,
				$instance->getParameters(),
				$instance->getInitializer(),
			);
			return $this->get($key);
		}
		
		if($attributeName === TypeLazy::class)
		{
			$instance = $attribute->newInstance();
			$this->registerLazy($key, $type,
				$instance->getParameters(),
				$instance->getInitializer(),
			);
			return $this->get($key);
		}
		
		if($attributeName === TypeCallable::class)
		{
			/** @var TypeCallable $instance */
			$instance = $attribute->newInstance();
			$this->registerCallable($key,
				$instance->getCallable(),
				$instance->getParameters(),
			);
			return $this->get($key);
		}
		
		return null;
	}
	
	/**
	 * @param ?ReflectionType $propertyType
	 *
	 * @return array
	 */
	protected function _getOwnTypes(?ReflectionType $propertyType): array
	{
		$types = [];
		if($propertyType instanceof ReflectionUnionType)
		{
			foreach($propertyType->getTypes() as $type)
			{
				if($type->isBuiltin()) // also null values
				{
					continue;
				}
				
				$types[] = $type->getName();
			}
		}
		else if($propertyType instanceof ReflectionNamedType
			&& $propertyType->isBuiltin() === false)
		{
			$types[] = $propertyType->getName();
		}
		
		return $types;
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
			$attributes = $property->getAttributes(Inject::class);
			if(count($attributes) === 0)
			{
				continue;
			}
			
			$resolved = $this->_resolveValueByKey($property)
				?? $this->_resolveValueByType($property);
			
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
		return isset($this->_resolvers[$key]);
	}
	
	/**
	 * @return array
	 */
	public function __debugInfo(): array
	{
		return array_keys($this->_resolvers);
	}
}

/**
 * @return Container
 */
function container(): Container
{
	static $container;
	if($container === null)
	{
		$container = new Container;
		$container->registerObject(Container::class, $container);
	}
	
	return $container;
}