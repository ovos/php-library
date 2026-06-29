<?php
declare(strict_types=1);

namespace Ovos\Model\Mysql;

use Ovos\Container;
use Ovos\Model\Mysql;
use Ovos\Application;
use Ovos\ArrayObject;

use function Ovos\container;
use function get_object_vars;

/**
 * Template
 *
 * @author Marcin Gil <mg@ovos.at>
 */
abstract class Template
{
	protected Container $container;
	
	protected Application $app;
	
	protected ArrayObject $config;
	
	public function __construct()
	{
		$this->__unserialize();
	}
	
	public function __serialize(): array
	{
		$properties = get_object_vars($this);
		unset(
			$properties['container'],
			$properties['app'],
			$properties['config'],
		);
		
		return $properties;
	}
	
	public function __unserialize(
		array $data = [],
	): void
	{
		foreach($data as $property => $value)
		{
			$this->$property = $value;
		}
		
		$this->container = container();
		$this->app = $this->container->get(Application::class);
		$this->config = $this->app->getConfig();
	}
	
	public function setUp(
		Mysql $model,
	): void
	{
	}
	
	public function preInsert(
		Mysql $model,
	): void
	{
	}
	
	public function preUpdate(
		Mysql $model,
	): void
	{
	}
	
	public function preSave(
		Mysql $model,
	): void
	{
	}
	
	public function preDelete(
		Mysql $model,
	): void
	{
	}
	
	public function postInsert(
		Mysql $model,
	): void
	{
	}
	
	public function postUpdate(
		Mysql $model,
	): void
	{
	}
	
	public function postSave(
		Mysql $model,
	): void
	{
	}
	
	public function postDelete(
		Mysql $model,
	): void
	{
	}
}
