<?php
declare(strict_types=1);

namespace Ovos;

use function get_object_vars;

/**
 * Model
 *
 * @author Marcin Gil <mg@ovos.at>
 */
abstract class Model
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
		$this->container = container();
		$this->app = $this->container->get(Application::class);
		$this->config = $this->app->getConfig();
		
		foreach($data as $property => $value)
		{
			$this->$property = $value;
		}
	}
	
	abstract public static function getStoreClass(): string;
}
