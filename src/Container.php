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
use ReflectionMethod;
use ReflectionParameter;
use ReflectionType;
use ReflectionNamedType;
use ReflectionUnionType;
use ReflectionProperty;

use function array_keys;
use function count;
use function implode;

/**
 * Container
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Container
{
	/**
	 * @var Injector[]
	 */
	protected array $injectors = [];
	
	protected array $resolved = [];
	
	protected array $resolving = [];
	
	/**
	 * Register a class
	 */
	public function registerClass(
		string $key,
		?string $class = null,
		array $parameters = [],
		?callable $initializer = null,
		bool $overwrite = false,
		bool $transient = false,
	): static
	{
		if(isset($this->injectors[$key]) // already registered
			&& $overwrite === false)
		{
			return $this;
		}
		
		$injector = new Injector\TypeClass(
			$class ?? $key,
			$parameters,
			$initializer,
		);
		$injector->setTransient($transient);
		$this->injectors[$key] = $injector;
		
		return $this;
	}
	
	/**
	 * Get a class and register it if needed
	 */
	public function getClass(
		string $key,
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
	 */
	public function registerLazy(
		string $key,
		?string $class = null,
		array $parameters = [],
		?callable $initializer = null,
		bool $overwrite = false,
	): static
	{
		if(isset($this->injectors[$key]) // already registered
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
		
		$this->injectors[$key] = new Injector\TypeLazy(
			$class ?? $key,
			$parameters,
			$initializer,
		);
		
		return $this;
	}
	
	/**
	 * Get a lazy object and register it if needed
	 */
	public function getLazy(
		string $key,
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
	 */
	public function registerCallable(
		string $key,
		callable $callable,
		array $parameters = [],
		bool $overwrite = false,
		bool $transient = false,
	): static
	{
		if(isset($this->injectors[$key]) // already registered
			&& $overwrite === false)
		{
			return $this;
		}
		
		$injector = new Injector\TypeCallable(
			$callable,
			$parameters,
		);
		$injector->setTransient($transient);
		$this->injectors[$key] = $injector;
		
		return $this;
	}
	
	/**
	 * Get a callable and register it if needed
	 */
	public function getCallable(
		string $key,
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
	 */
	public function registerObject(
		string $key,
		object $object,
		?callable $initializer = null,
		bool $overwrite = false,
	): static
	{
		if(isset($this->injectors[$key]) // already registered
			&& $overwrite === false)
		{
			return $this;
		}
		
		$this->injectors[$key] = new Injector\TypeObject(
			$object,
			$initializer,
		);
		
		return $this;
	}
	
	/**
	 * Get an object and register it if needed
	 */
	public function getObject(
		string $key,
		object $object,
	): ?object
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
	 */
	public function registerValue(
		string $key,
		mixed $value,
		bool $overwrite = false,
	): static
	{
		if(isset($this->injectors[$key]) // already registered
			&& $overwrite === false)
		{
			return $this;
		}
		
		// two plain assignments, never a reference between the maps: a
		// reference survives an array copy, so any snapshot of these maps
		// (tests swap entries that way) would write straight through into
		// the container instead of into its own copy
		$this->injectors[$key] = $value;
		$this->resolved[$key] = $value;
		
		return $this;
	}
	
	/**
	 * Get a value and register it if needed
	 */
	public function getValue(
		string $key,
		mixed $value,
	): mixed
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
	 */
	public function get(
		string $key,
	): mixed
	{
		if(($resolved = $this->resolve($key)) === null)
		{
			throw new Exception('Dependency "%s" not registered.', $key);
		}
		
		return $resolved;
	}
	
	public function resolve(
		string $key,
	): mixed
	{
		if(isset($this->resolved[$key]))
		{
			return $this->resolved[$key];
		}
		
		if(isset($this->injectors[$key]) === false)
		{
			return null;
		}
		
		if(($this->injectors[$key] instanceof Injector) === false)
		{
			return null;
		}
		
		if(isset($this->resolving[$key]))
		{
			$chain = array_keys($this->resolving);
			$chain[] = $key;
			
			throw new Exception(
				'Circular dependency detected: %s.',
				implode(' -> ', $chain),
			);
		}
		
		$this->resolving[$key] = true;
		
		try
		{
			$injector = $this->injectors[$key];
			$result = $this->inject($injector);
			
			if($injector->isTransient() === false)
			{
				$this->resolved[$key] = $result;
			}
			
			return $result;
		}
		finally
		{
			unset($this->resolving[$key]);
		}
	}
	
	/**
	 * Injects in constructor
	 * or marked with #[Inject] attribute
	 * Does not register the resolver in the container
	 */
	public function inject(
		Injector $injector,
	): object
	{
		return $injector->inject($this);
	}
	
	/**
	 * @see inject
	 */
	public function injectClass(
		string $class,
		array $parameters = [],
		?callable $initializer = null,
	): object
	{
		return (new Injector\TypeClass($class, $parameters, $initializer))
			->inject($this);
	}
	
	/**
	 * @see inject
	 */
	public function injectLazy(
		string $class,
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
	 */
	public function injectCallable(
		callable $callable,
		array $parameters = [],
	): object
	{
		return (new Injector\TypeCallable($callable, $parameters))
			->inject($this);
	}
	
	/**
	 * @see inject
	 */
	public function injectObject(
		object $object,
		?callable $initializer = null,
	): object
	{
		return (new Injector\TypeObject($object, $initializer))
			->inject($this);
	}
	
	/**
	 * Complete an object built with `new`: the #[Inject] properties it has
	 * NOT initialized are resolved, what its constructor (or a caller) set
	 * stays — injectObject() would overwrite a hand-given config with the
	 * container's. For a test double, or any instance the container did not
	 * construct itself.
	 */
	public function injectMissing(
		object $object,
	): object
	{
		$this->resolveProperties(new ReflectionClass($object), $object, onlyMissing: true);
		
		return $object;
	}
	
	/**
	 * Inject constructor parameters
	 */
	public function injectConstructor(
		ReflectionClass $reflector,
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
				= $this->injectParameter($parameter, $values)) === null)
			{
				continue;
			}
			
			$parameters[$parameter->getName()] = $resolved;
		}
		
		return $parameters;
	}
	
	/**
	 * Resolve method parameters and invoke it
	 */
	public function call(
		object $target,
		string $method,
		array $values = [],
	): mixed
	{
		$reflector = new ReflectionMethod($target, $method);
		
		$parameters = [];
		foreach($reflector->getParameters() as $parameter)
		{
			if(($resolved
				= $this->injectParameter($parameter, $values)) === null)
			{
				continue;
			}
			
			$parameters[$parameter->getName()] = $resolved;
		}
		
		return $reflector->invokeArgs($target, $parameters);
	}
	
	/**
	 * Inject a single constructor parameter
	 */
	protected function injectParameter(
		ReflectionParameter $parameter,
		array $values = [],
	): mixed
	{
		$resolved = $this->injectValueByName($parameter->getName(),
			$values)
			?? $this->injectValueByKey($parameter)
			?? $this->injectValueByType($parameter);
		
		if($resolved !== null)
		{
			return $this->processAttributes($parameter, $resolved);
		}
		
		return null;
	}
	
	/**
	 * Match parameters by name (and return its value if found)
	 */
	protected function injectValueByName(
		string $parameterName,
		array $parameters,
	): mixed
	{
		if(array_key_exists($parameterName, $parameters) !== false)
		{
			return $parameters[$parameterName];
		}
		
		return null;
	}
	
	protected function injectValueByKey(
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
		
		$object = $this->resolveTypes($arguments);
		
		// return an object if we managed to resolve it
		if($object !== null)
		{
			return $object;
		}
		
		// could not be resolved,
		// try to autoregister with the key
		return $this->injectValueByType($property, $arguments[0]);
	}
	
	/**
	 * Match parameters by type (and resolve them)
	 */
	protected function injectValueByType(
		ReflectionProperty|ReflectionParameter $property,
		?string $key = null,
	): mixed
	{
		$propertyType = $property->getType();
		if($propertyType === null)
		{
			return null;
		}
		
		$types = $this->getOwnTypes($propertyType);
		if(count($types) === 0)
		{
			return null;
		}
		
		$object = $this->resolveTypes($types);
		
		// return an object if we managed to resolve it
		if($object !== null)
		{
			return $object;
		}
		
		$key = $key ?? $types[0];
		
		// could not be resolved,
		// try to autoregister with the first type
		return $this->register($property, $key, $types[0]);
	}
	
	/**
	 * Resolve a list of types (return the first matching object)
	 */
	protected function resolveTypes(
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
	 */
	public function register(
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
	 */
	protected function getOwnTypes(
		?ReflectionType $propertyType,
	): array
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
	 * Resolve object's properties — every #[Inject] property, or with
	 * $onlyMissing only those the object has not initialized yet
	 */
	public function resolveProperties(
		ReflectionClass $reflector,
		object $object,
		bool $lazy = false,
		bool $onlyMissing = false,
	): void
	{
		foreach($reflector->getProperties() as $property)
		{
			$attributes = $property->getAttributes(Inject::class);
			if(count($attributes) === 0)
			{
				continue;
			}
			
			if($onlyMissing && $property->isInitialized($object))
			{
				continue;
			}
			
			$resolved = $this->injectValueByKey($property)
				?? $this->injectValueByType($property);
			
			if($resolved !== null)
			{
				$resolved = $this->processAttributes($property, $resolved);
				$this->injectValue($property, $object, $resolved, $lazy);
			}
		}
	}
	
	/**
	 * Process optional attributes
	 */
	protected function processAttributes(
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
	 */
	protected function injectValue(
		ReflectionProperty $property,
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
	 */
	public function isRegistered(
		string $key,
	): bool
	{
		return isset($this->injectors[$key]);
	}
	
	/**
	 * Whether the key has already been RESOLVED — constructed and cached —
	 * as opposed to merely registered. The distinction matters to observers
	 * that must never cause work: resolving a registered-but-unbuilt service
	 * runs its constructor, and a constructor may reach for the session or
	 * the database, which a read-only consumer (a shutdown-time metric, a
	 * debug surface) has no business triggering.
	 */
	public function isResolved(
		string $key,
	): bool
	{
		return isset($this->resolved[$key]);
	}
	
	public function __debugInfo(): array
	{
		return array_keys($this->injectors);
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