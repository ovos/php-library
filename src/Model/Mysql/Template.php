<?php
declare(strict_types=1);

namespace Ovos\Model\Mysql;

use Ovos\Container;
use Ovos\Model\Mysql;
use Ovos\Application;
use Ovos\ArrayObject;

use function Ovos\container;
use function array_keys;
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
		unset($properties['_app'], $properties['_config']);
		
		return array_keys($properties);
	}
	
	public function __unserialize(
		array $data = [],
	): void
	{
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
}
