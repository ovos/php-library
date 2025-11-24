<?php
declare(strict_types=1);

namespace Ovos;

use Ovos\Container\Injector;
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
	 * @var Injector[]
	 */
	protected array $_injectors = [];
	
	/**
	 * @var array
	 */
	protected array $_resolved = [];
	
	/**
	 * Register a class
	 *
	 * @param string $key
	 * @param ?string $class
	 * @param array $parameters
	 * @param ?callable $initializer
	 * @param bool $overwrite
	 *
	 * @return static
	 */
	public function registerClass(string $key,
		?string $class = null,
		array $parameters = [],
		?callable $initializer = null,
		bool $overwrite = false,
	): static
	{
		if(isset($this->_injectors[$key]) // already registered
			&& $overwrite === false)
		{
			return $this;
		}
		
		$this->_injectors[$key] = new Injector\TypeClass(
			$class ?? $key,
			$parameters,
			$initializer,
		);
		
		return $this;
	}
	
	/**
	 * Get a class and register it if needed
	 *
	 * @param string $key
	 * @param ?string $class
	 * @param array $parameters
	 * @param ?callable $initializer
	 *
	 * @return object
	 */
	public function getClass(string $key,
		?string $class = null,
		array $parameters = [],
		?callable $initializer = null,
	): object
	{
		if(($resolved = $this->resolve($key)) !== null)
		{
			return $resolved;
		}
		
		return $this
			->registerClass(
				$key,
				$class ?? $key,
				$parameters,
				$initializer,
			)
			->get($key);
	}
	
	/**
	 * Register a lazy object
	 *
	 * @param string $key
	 * @param ?string $class
	 * @param array $parameters
	 * @param ?callable $initializer
	 * @param bool $overwrite
	 * 
	 * @return static
	 */
	public function registerLazy(string $key,
		?string $class = null,
		array $parameters = [],
		?callable $initializer = null,
		bool $overwrite = false,
	): static
	{
		if(isset($this->_injectors[$key]) // already registered
			&& $overwrite === false)
		{
			return $this;
		}
		
		// compatibility with pre 8.4
		if(PHP_VERSION_ID < 84000)
		{
			return $this->registerClass(
				$key,
				$class ?? $key,
				$parameters,
				$initializer,
			);
		}
		
		$this->_injectors[$key] = new Injector\TypeLazy(
			$class ?? $key,
			$parameters,
			$initializer,
		);
		
		return $this;
	}
	
	/**
	 * Get a lazy object and register it if needed
	 *
	 * @param string $key
	 * @param ?string $class
	 * @param array $parameters
	 * @param ?callable $initializer
	 *
	 * @return object
	 */
	public function getLazy(string $key,
		?string $class = null,
		array $parameters = [],
		?callable $initializer = null,
	): object
	{
		if(($resolved = $this->resolve($key)) !== null)
		{
			return $resolved;
		}
		
		return $this
			->registerLazy(
				$key,
				$class ?? $key,
				$parameters,
				$initializer,
			)
			->get($key);
	}
	
	/**
	 * Register a callable and instantiate it on demand
	 *
	 * @param string $key
	 * @param callable $callable
	 * @param array $parameters
	 * @param bool $overwrite
	 *
	 * @return static
	 */
	public function registerCallable(string $key,
		callable $callable,
		array $parameters = [],
		bool $overwrite = false,
	): static
	{
		if(isset($this->_injectors[$key]) // already registered
			&& $overwrite === false)
		{
			return $this;
		}
		
		$this->_injectors[$key] = new Injector\TypeCallable(
			$callable,
			$parameters,
		);
		
		return $this;
	}
	
	/**
	 * Get a callable and register it if needed
	 *
	 * @param string $key
	 * @param callable $callable
	 * @param array $parameters
	 *
	 * @return object
	 */
	public function getCallable(string $key,
		callable $callable,
		array $parameters = [],
	): object
	{
		if(($resolved = $this->resolve($key)) !== null)
		{
			return $resolved;
		}
		
		return $this
			->registerCallable(
				$key,
				$callable,
				$parameters,
			)
			->get($key);
	}
	
	/**
	 * Register an instance of an object
	 * No need to resolve dependencies
	 *
	 * @param string $key
	 * @param object $object
	 * @param ?callable $initializer
	 * @param bool $overwrite
	 *
	 * @return static
	 */
	public function registerObject(string $key,
		object $object,
		?callable $initializer = null,
		bool $overwrite = false,
	): static
	{
		if(isset($this->_injectors[$key]) // already registered
			&& $overwrite === false)
		{
			return $this;
		}
		
		$this->_injectors[$key] = new Injector\TypeObject(
			$object,
			$initializer,
		);
		
		return $this;
	}
	
	/**
	 * Get an object and register it if needed
	 *
	 * @param string $key
	 * @param object $object
	 *
	 * @return ?object
	 */
	public function getObject(string $key, object $object): ?object
	{
		if(($resolved = $this->resolve($key)) !== null)
		{
			return $resolved;
		}
		
		return $this
			->registerObject(
				$key,
				$object,
			)
			->get($key);
	}
	
	/**
	 * Register any value without resolving dependencies
	 *
	 * @param string $key
	 * @param mixed $value
	 * @param bool $overwrite
	 *
	 * @return static
	 */
	public function registerValue(string $key,
		mixed $value,
		bool $overwrite = false,
	): static
	{
		if(isset($this->_injectors[$key]) // already registered
			&& $overwrite === false)
		{
			return $this;
		}
		
		$this->_injectors[$key] = $value;
		$this->_resolved[$key] = &$this->_injectors[$key];
		
		return $this;
	}
	
	/**
	 * Get a value and register it if needed
	 *
	 * @param string $key
	 * @param mixed $value
	 *
	 * @return mixed
	 */
	public function getValue(string $key, mixed $value): mixed
	{
		if(($resolved = $this->resolve($key)) !== null)
		{
			return $resolved;
		}
		
		return $this
			->registerValue(
				$key,
				$value,
			)
			->get($key);
	}
	
	/**
	 * Returns a resolved object or value
	 *
	 * @param string $key
	 *
	 * @return mixed
	 */
	public function get(string $key): mixed
	{
		if(($resolved = $this->resolve($key)) === null)
		{
			throw new Exception('Dependency "%s" not registered.', $key);
		}
		
		return $resolved;
	}
	
	/**
	 * @param string $key
	 *
	 * @return mixed
	 */
	public function resolve(string $key): mixed
	{
		if(isset($this->_resolved[$key]))
		{
			return $this->_resolved[$key];
		}
		
		if(isset($this->_injectors[$key]) === false)
		{
			return null;
		}
			
		if(($this->_injectors[$key] instanceof Injector) === false)
		{
			return null;
		}
		
		return $this->_resolved[$key]
			= $this->inject($this->_injectors[$key]);
	}
	
	/**
	 * Injects in constructor
	 * or marked with #[Inject] attribute
	 * Does not register the resolver in the container
	 *
	 * @param Injector $injector
	 *
	 * @return object
	 */
	public function inject(Injector $injector): object
	{
		return $injector->inject($this);
	}
	
	/**
	 * @see inject
	 *
	 * @param string $class
	 * @param array $parameters
	 * @param ?callable $initializer
	 *
	 * @return object
	 */
	public function injectClass(string $class,
		array $parameters = [],
		?callable $initializer = null,
	): object
	{
		return (new Injector\TypeClass($class, $parameters, $initializer))
			->inject($this);
	}
	
	/**
	 * @see inject
	 *
	 * @param string $class
	 * @param array $parameters
	 * @param ?callable $initializer
	 *
	 * @return object
	 */
	public function injectLazy(string $class,
		array $parameters = [],
		?callable $initializer = null,
	): object
	{
		// compatibility with pre 8.4
		if(PHP_VERSION_ID < 84000)
		{
			return $this->injectClass(
				$class,
				$parameters,
				$initializer,
			);
		}
		
		return (new Injector\TypeClass($class, $parameters, $initializer))
			->inject($this);
	}
	
	/**
	 * @see inject
	 *
	 * @param callable $callable
	 * @param array $parameters
	 *
	 * @return object
	 */
	public function injectCallable(callable $callable,
		array $parameters = [],
	): object
	{
		return (new Injector\TypeCallable($callable, $parameters))
			->inject($this);
	}
	
	/**
	 * @see inject
	 *
	 * @param object $object
	 * @param ?callable $initializer
	 *
	 * @return object
	 */
	public function injectObject(object $object,
		?callable $initializer = null,
	): object
	{
		return (new Injector\TypeObject($object, $initializer))
			->inject($this);
	}
	
	/**
	 * Inject constructor parameters
	 *
	 * @param ReflectionClass $reflector
	 * @param array $values
	 *
	 * @return array
	 */
	public function injectConstructor(ReflectionClass $reflector,
		array $values = [],
	): array
	{
		if(($constructor = $reflector->getConstructor()) === null)
		{
			return [];
		}
		
		$parameters = [];
		foreach($constructor->getParameters() as $parameter)
		{
			if(($resolved
				= $this->_injectParameter($parameter, $values)) === null)
			{
				continue;
			}
			
			$parameters[$parameter->getName()] = $resolved;
		}
		
		return $parameters;
	}
	
	/**
	 * Inject a single constructor parameter
	 *
	 * @param ReflectionParameter $parameter
	 * @param array $values
	 *
	 * @return mixed
	 */
	protected function _injectParameter(
		ReflectionParameter $parameter,
		array $values = [],
	): mixed
	{
		$resolved = $this->_injectValueByName($parameter->getName(),
			$values)
			?? $this->_injectValueByKey($parameter)
			?? $this->_injectValueByType($parameter);
		
		if($resolved !== null)
		{
			return $this->_processAttributes($parameter, $resolved);
		}
		
		return null;
	}
	
	/**
	 * Match parameters by name (and return its value if found)
	 *
	 * @param string $parameterName
	 * @param array $parameters
	 *
	 * @return mixed
	 */
	protected function _injectValueByName(string $parameterName,
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
	protected function _injectValueByKey(
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
		return $this->_injectValueByType($property, $arguments[0]);
	}
	
	/**
	 * Match parameters by type (and resolve them)
	 *
	 * @param ReflectionProperty|ReflectionParameter $property
	 * @param ?string $key
	 *
	 * @return mixed
	 */
	protected function _injectValueByType(
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
	 * Resolve a list of types (return the first matching object)
	 *
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
			if(($object = $this->resolve($type)) !== null)
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
	 * Loop properties and return only the own types
	 *
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
	 * Resolve object's properties
	 *
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
			
			$resolved = $this->_injectValueByKey($property)
				?? $this->_injectValueByType($property);
			
			if($resolved !== null)
			{
				$resolved = $this->_processAttributes($property, $resolved);
				$this->_injectValue($property, $object, $resolved, $lazy);
			}
		}
	}
	
	/**
	 * Process optional attributes
	 *
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
	 * Set a value on an object's property
	 *
	 * @param ReflectionProperty $property
	 * @param object $object
	 * @param mixed $resolved
	 * @param bool $lazy
	 *
	 * @return void
	 */
	protected function _injectValue(ReflectionProperty $property,
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
		return isset($this->_injectors[$key]);
	}
	
	/**
	 * @return array
	 */
	public function __debugInfo(): array
	{
		return array_keys($this->_injectors);
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