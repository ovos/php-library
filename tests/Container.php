<?php
declare(strict_types=1);

namespace Tests;

use Ovos\ArrayObject as BaseArrayObject;
use Ovos\Arrays;
use Ovos\Exception;
use Ovos\Test;
use Ovos\Container as BaseContainer;
use Ovos\Container\ArrayObject;
use Ovos\Container\Inject;
use Ovos\Container\Register\TypeClass;
use Ovos\Container\Register\TypeLazy;
use ReflectionProperty;

/**
 * Container
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Container extends Test
{
	public function registerClass(): bool
	{
		$container = new BaseContainer;
		$container->registerClass(Dependency1::class, Dependency1::class);
		$container->registerClass(Service1::class,
			Service1::class,
			Service1::$parameters,
		);
		
		$instance = $container->get(Service1::class);
		
		return $instance->dependency1 instanceof Dependency1
			&& $instance->value === 'test';
	}
	
	public function injectClass(): bool
	{
		$container = new BaseContainer;
		$container->registerClass(Dependency1::class, Dependency1::class);
		$instance = $container->injectClass(Service1::class,
			Service1::$parameters,
		);
		
		return $instance->dependency1 instanceof Dependency1
			&& $instance->value === 'test';
	}
	
	public function getClass(): bool
	{
		$container = new BaseContainer;
		$instance = $container->getClass(Service1::class,
			parameters: Service1::$parameters,
		);
		
		return $instance->dependency1 instanceof Dependency1
			&& $instance->value === 'test';
	}
	
	public function registerCallable(): bool
	{
		$container = new BaseContainer;
		$container->registerCallable(Service1::class,
			function(BaseContainer $container, array $parameters)
			{
				return new Service1
				(
					$container->getClass(Dependency1::class),
					$parameters['value'],
				);
			}
		, Service1::$parameters);
		
		$instance = $container->get(Service1::class);
		
		return $instance->dependency1 instanceof Dependency1
			&& $instance->value === 'test';
	}
	
	public function injectCallable(): bool
	{
		$container = new BaseContainer;
		$instance = $container->injectCallable(
			function(BaseContainer $container, array $parameters)
			{
				return new Service1
				(
					$container->getClass(Dependency1::class),
					$parameters['value'],
				);
			}
		, Service1::$parameters);
		
		return $instance->dependency1 instanceof Dependency1
			&& $instance->value === 'test';
	}
	
	public function registerCallableWithDependencies(): bool
	{
		$container = new BaseContainer;
		$container->registerCallable(Dependency1::class, fn() => new Dependency1);
		$container->registerCallable(Dependency2::class, fn() => new Dependency2);
		
		$container->registerCallable(Service1::class,
			function(BaseContainer $container)
			{
				return new Service1
				(
					$container->get(Dependency1::class),
					Service1::$parameters['value'],
					$container->get(Dependency2::class)
				);
			}
		);
		
		$instance = $container->get(Service1::class);
		
		return $instance->dependency1 instanceof Dependency1
			&& $instance->dependency2 instanceof Dependency2
			&& $instance->value === 'test';
	}
	
	public function getCallable(): bool
	{
		$container = new BaseContainer;
		$instance = $container->getCallable(Service1::class,
			function(BaseContainer $container, array $parameters)
			{
				return new Service1
				(
					$container->getClass(Dependency1::class),
					$parameters['value'],
				);
			}
		, Service1::$parameters);
		
		return $instance->dependency1 instanceof Dependency1
			&& $instance->value === 'test';
	}
	
	public function registerObject(): bool
	{
		$container = new BaseContainer;
		$container->registerObject(Service1::class, new Service1(
			$container->getClass(Dependency1::class, Dependency1::class),
			Service1::$parameters['value'],
		));
		
		$instance = $container->get(Service1::class);
		
		return $instance->dependency1 instanceof Dependency1
			&& $instance->value === 'test';
	}
	
	public function injectObject(): bool
	{
		$container = new BaseContainer;
		$instance = $container->injectObject(new Service1(
			$container->getClass(Dependency1::class, Dependency1::class),
			Service1::$parameters['value'],
		));
		
		return $instance->dependency1 instanceof Dependency1
			&& $instance->value === 'test';
	}
	
	public function registerLazy(): bool
	{
		$container = new BaseContainer;
		$container->registerLazy(Dependency1::class);
		$container->registerLazy(Service1::class,
			Service1::class,
			Service1::$parameters,
		);
		
		$instance = $container->get(Service1::class);
		
		return $instance->dependency1 instanceof Dependency1
			&& $instance->value === 'test';
	}
	
	public function injectLazy(): bool
	{
		$container = new BaseContainer;
		$container->registerLazy(Dependency1::class);
		$instance = $container->injectLazy(
			Service1::class,
			Service1::$parameters,
		);
		
		return $instance->dependency1 instanceof Dependency1
			&& $instance->value === 'test';
	}
	
	/**
	 * isResolved() distinguishes CONSTRUCTED from merely registered, and the
	 * asking must never construct anything — that is its whole point: a
	 * read-only consumer (a shutdown-time metric) uses it to avoid running a
	 * constructor that may reach for the session or the database.
	 */
	public function isResolvedNeverConstructsWhatItAsksAbout(): bool
	{
		$container = new BaseContainer;
		$container->registerClass(Dependency1::class);
		
		$registeredOnly = $container->isResolved(Dependency1::class);
		$unknown = $container->isResolved('never-registered');
		
		$container->get(Dependency1::class);
		
		return $registeredOnly === false
			&& $unknown === false
			// asking did not resolve it — only the get() above did
			&& $container->isResolved(Dependency1::class) === true;
	}
	
	public function registerLazyWithCustomInitializer(): bool
	{
		$container = new BaseContainer;
		$container->registerClass(Dependency1::class);
		$container->registerLazy(Service1::class,
			Service1::class,
			initializer: function() use ($container)
			{
				return new Service1
				(
					$container->get(Dependency1::class),
					Service1::$parameters['value'],
				);
			},
		);
		
		$instance = $container->get(Service1::class);
		
		return $instance->dependency1 instanceof Dependency1
			&& $instance->value === 'test';
	}
	
	public function registerLazyWithAnOptionalDependency(): bool
	{
		$container = new BaseContainer;
		$container->registerLazy(Dependency1::class);
		$container->registerLazy(Dependency2::class);
		
		$container->registerLazy(Service1::class,
			Service1::class,
			Service1::$parameters,
		);
		
		$instance = $container->get(Service1::class);
		
		return $instance->dependency1 instanceof Dependency1
			&& $instance->dependency2 instanceof Dependency2
			&& $instance->value === 'test';
	}
	
	public function getLazy(): bool
	{
		$container = new BaseContainer;
		$instance = $container->getLazy(Service1::class,
			Service1::class,
			Service1::$parameters,
		);
		
		return $instance->dependency1 instanceof Dependency1
			&& $instance->value === 'test';
	}
	
	/**
	 * @return BaseArrayObject
	 */
	protected function getExampleArrayObject(): BaseArrayObject
	{
		return Arrays::deepToArrayObject([
			'system' => [
				'database' => [
					'credentials' => [
						'username' => 'root',
						'password' => 'root',
					]
				],
			],
		]);
	}
	
	public function attributesProperties(): bool
	{
		$arrayObject = $this->getExampleArrayObject();
		
		$container = new BaseContainer;
		$container->registerObject('config', $arrayObject);
		$container->registerClass(Dependency1::class);
		$container->registerClass(Service2::class,
			Service2::class
		);
		
		$instance = $container->get(Service2::class);
		
		return $instance->config->offsetGet('username') === 'root';
	}
	
	public function attributesParameters(): bool
	{
		$arrayObject = $this->getExampleArrayObject();
		
		$container = new BaseContainer;
		$container->registerObject('config', $arrayObject);
		$container->registerClass(Service3::class,
			Service3::class
		);
		
		$instance = $container->get(Service3::class);
		
		return $instance->config->offsetGet('username') === 'root';
	}
	
	public function attributesAutomaticRegistrationParameters(): bool
	{
		$container = new BaseContainer;
		$container->registerClass(Service1::class,
			Service1::class,
		);
		
		$instance = $container->get(Service1::class);
		
		return $instance->dependency1 instanceof Dependency1
			&& $instance->dependency2 instanceof Dependency2;
	}
	
	public function attributesAutomaticRegistrationProperties(): bool
	{
		$container = new BaseContainer;
		$container->registerClass(Service4::class,
			Service4::class,
		);
		
		$instance = $container->get(Service4::class);
		
		return $instance->dependency1 instanceof Dependency1
			&& $instance->dependency2 instanceof Dependency2;
	}
	
	public function attributesAutomaticNoRegistrationProperties(): bool
	{
		$container = new BaseContainer;
		$container->registerClass(Service5::class,
			Service5::class,
		);
		
		$instance = $container->get(Service5::class);
		
		return $instance->dependency1 === null
			&& $instance->dependency2 === null;
	}
	
	public function attributesAutomaticRegistrationParameterKey(): bool
	{
		$container = new BaseContainer;
		$container->registerClass(Service6::class,
			Service6::class
		);
		
		$instance = $container->get(Service6::class);
		
		return $container->get('dependency1') instanceof Dependency1;
	}
	
	public function mixed(): bool
	{
		$container = new BaseContainer;
		$instance = $container->getClass(Service7::class,
			Service7::class,
			Service7::$parameters,
		);
		
		return $instance->dependency1 instanceof Dependency1
			&& $instance->dependency2 instanceof Dependency2
			&& $instance->value === 'test';
	}
	
	public function circularDependencyDetection(): bool
	{
		$container = new BaseContainer;
		$container->registerClass(CircularA::class);
		$container->registerClass(CircularB::class);
		
		try
		{
			$container->get(CircularA::class);
			return false;
		}
		catch(Exception $exception)
		{
			return str_contains($exception->getMessage(),
				'Circular dependency detected');
		}
	}
	
	public function transientClass(): bool
	{
		$container = new BaseContainer;
		$container->registerClass(Dependency1::class, transient: true);
		
		$a = $container->get(Dependency1::class);
		$b = $container->get(Dependency1::class);
		
		return $a instanceof Dependency1
			&& $b instanceof Dependency1
			&& $a !== $b;
	}
	
	public function transientCallable(): bool
	{
		$container = new BaseContainer;
		$container->registerCallable(Dependency1::class,
			fn() => new Dependency1,
			transient: true,
		);
		
		$a = $container->get(Dependency1::class);
		$b = $container->get(Dependency1::class);
		
		return $a instanceof Dependency1
			&& $b instanceof Dependency1
			&& $a !== $b;
	}
	
	public function singletonByDefault(): bool
	{
		$container = new BaseContainer;
		$container->registerClass(Dependency1::class);
		
		$a = $container->get(Dependency1::class);
		$b = $container->get(Dependency1::class);
		
		return $a === $b;
	}
	
	public function call(): bool
	{
		$container = new BaseContainer;
		$container->registerClass(Dependency1::class);
		
		$service = new CallableService;
		$result = $container->call($service, 'doWork', [
			'value' => 'test',
		]);
		
		return $result === 'test'
			&& $service->dependency1 instanceof Dependency1;
	}
	
	public function callWithInjectAttribute(): bool
	{
		$arrayObject = $this->getExampleArrayObject();
		
		$container = new BaseContainer;
		$container->registerObject('config', $arrayObject);
		
		$service = new CallableService;
		$result = $container->call($service, 'doWorkWithConfig');
		
		return $result === 'root';
	}
	
	/**
	 * registerValue() used to bind resolved[$key] as a REFERENCE to
	 * injectors[$key]. A reference survives an array copy, so a test helper
	 * that snapshots the container maps via reflection and writes a stub into
	 * its own copy wrote straight through into the live container — and into
	 * its own before-snapshot, so a finally-restore put the stub back instead
	 * of the original. The stub then leaked into every later test resolving
	 * that key, in whichever order the filesystem happened to dictate: green
	 * on one OS, red on another.
	 */
	public function registerValueLeavesNoReferenceBehind(): bool
	{
		$container = new BaseContainer;
		$container->registerValue('service', 'real');
		
		foreach(['injectors', 'resolved'] as $property)
		{
			$copy = (new ReflectionProperty(BaseContainer::class, $property))
				->getValue($container);
			$copy['service'] = 'stub';
		}
		
		return $container->get('service') === 'real'
			// and the overwrite still updates both maps without the reference
			&& $container->registerValue('service', 'replaced', overwrite: true)
				->get('service') === 'replaced';
	}
	
	/**
	 * injectMissing() completes an object built with `new`: the #[Inject]
	 * property a constructor set stays (injectObject() would replace it with
	 * the container's own), the one left empty is resolved
	 */
	public function injectMissingKeepsWhatWasSetAndFillsTheRest(): bool
	{
		$container = new BaseContainer;
		$own = new Dependency1;
		
		$kept = new Service8;
		$kept->dependency1 = $own;
		$container->injectMissing($kept);
		
		$replaced = new Service8;
		$replaced->dependency1 = $own;
		$container->injectObject($replaced);
		
		return $kept->dependency1 === $own
			&& $kept->dependency2 instanceof Dependency2
			&& $replaced->dependency1 !== $own
			&& $replaced->dependency1 instanceof Dependency1;
	}
}

class Service1
{
	public static $parameters = [
		'value' => 'test',
	];
	
	public function __construct
	(
		public Dependency1 $dependency1,
		public ?string $value = null,
		public ?Dependency2 $dependency2 = null,
	)
	{
	}
}

class Service2
{
	#[Inject]
	public Dependency1 $dependency1;
	#[Inject('config')]
	#[ArrayObject('system', 'database', 'credentials')]
	public BaseArrayObject $config;
	#[Inject]
	public ?Dependency2 $dependency2 = null;
}

class Service3
{
	public function __construct
	(
		#[Inject('config')]
		#[ArrayObject('system', 'database', 'credentials')]
		public BaseArrayObject $config,
	)
	{
	}
}

class Service4
{
	#[TypeClass]
	#[Inject]
	public Dependency1 $dependency1;
	#[TypeLazy]
	#[Inject]
	public ?Dependency2 $dependency2 = null;
}

class Service5
{
	public ?Dependency1 $dependency1 = null;
	public ?Dependency2 $dependency2 = null;
}

class Service6
{
	public function __construct
	(
		#[Inject('dependency1')]
		public Dependency1 $dependency1,
	)
	{
	}
}

class Service7
{
	public static $parameters = [
		'value' => 'test',
	];
	
	#[Inject]
	public Dependency1 $dependency1;
	
	public function __construct
	(
		public ?string $value = null,
		public ?Dependency2 $dependency2 = null,
	)
	{
	}
}

class Service8
{
	#[Inject]
	public Dependency1 $dependency1;
	#[Inject]
	public Dependency2 $dependency2;
}

class Dependency1
{
}

class Dependency2
{
}

class CircularA
{
	public function __construct(
		public CircularB $b,
	)
	{
	}
}

class CircularB
{
	public function __construct(
		public CircularA $a,
	)
	{
	}
}

class CallableService
{
	public ?Dependency1 $dependency1 = null;
	
	public function doWork(
		Dependency1 $dependency1,
		string $value,
	): string
	{
		$this->dependency1 = $dependency1;
		
		return $value;
	}
	
	public function doWorkWithConfig(
		#[Inject('config')]
		#[ArrayObject('system', 'database', 'credentials')]
		BaseArrayObject $config,
	): string
	{
		return $config->offsetGet('username');
	}
}
