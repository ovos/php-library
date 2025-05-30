<?php
declare(strict_types=1);

namespace Tests;

use Ovos\ArrayObject as BaseArrayObject;
use Ovos\Arrays as Arrays;
use Ovos\Services;
use Ovos\Test;
use Ovos\Container as BaseContainer;
use Ovos\Container\ArrayObject;
use Ovos\Container\Inject;
use Ovos\Container\Register\TypeClass;
use Ovos\Container\Register\TypeLazy;

/**
 * Container
 *
 * @package Tests
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
	
	public function registerCallable(): bool
	{
		$container = new BaseContainer;
		$container->registerCallable(Service1::class,
			function(BaseContainer $container, array $parameters)
			{
				return new Service1
				(
					new Dependency1,
					$parameters['value'],
				);
			}
		, Service1::$parameters);
		
		$instance = $container->get(Service1::class);
		
		return $instance->dependency1 instanceof Dependency1
			&& $instance->value === 'test';
	}
	
	public function registerLazy(): bool
	{
		$container = new BaseContainer;
		$container->registerClass(Dependency1::class, Dependency1::class);
		$container->registerLazy(Service1::class,
			Service1::class,
			Service1::$parameters,
		);
		
		$instance = $container->get(Service1::class);
		
		return $instance->dependency1 instanceof Dependency1
			&& $instance->value === 'test';
	}
	
	public function registerLazyWithCustomInitializer(): bool
	{
		$container = new BaseContainer;
		$container->registerClass(Dependency1::class, Dependency1::class);
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
		$container->registerCallable(Dependency1::class, fn() => new Dependency1);
		$container->registerCallable(Dependency2::class, fn() => new Dependency2);
		
		$container->registerLazy(Service1::class,
			Service1::class,
			Service1::$parameters,
		);
		
		$instance = $container->get(Service1::class);
		
		return $instance->dependency1 instanceof Dependency1
			&& $instance->dependency2 instanceof Dependency2
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
	
	/**
	 * @return ArrayObject
	 */
	protected function _getExampleArrayObject(): BaseArrayObject
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
		$arrayObject = $this->_getExampleArrayObject();
		
		$container = new BaseContainer;
		$container->registerObject('config', $arrayObject);
		$container->registerClass(Dependency1::class, Dependency1::class);
		$container->registerClass(Service2::class,
			Service2::class
		);
		
		/** @var BaseArrayObject $instance */
		$instance = $container->get(Service2::class);
		
		return $instance->config->offsetGet('username') === 'root';
	}
	
	public function attributesParameters(): bool
	{
		$arrayObject = $this->_getExampleArrayObject();
		
		$container = new BaseContainer;
		$container->registerObject('config', $arrayObject);
		$container->registerClass(Service3::class,
			Service3::class
		);
		
		/** @var BaseArrayObject $instance */
		$instance = $container->get(Service3::class);
		
		return $instance->config->offsetGet('username') === 'root';
	}
	
	public function attribuesAutomaticRegistration(): bool
	{
		$container = new BaseContainer;
		$container->registerClass(Service4::class,
			Service4::class,
		);
		
		$instance = $container->get(Service4::class);
		
		return $instance->dependency1 instanceof Dependency1
			&& $instance->dependency2 instanceof Dependency2;
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

class Dependency1
{
}

class Dependency2
{
}
