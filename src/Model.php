<?php
declare(strict_types=1);

namespace Ovos;

use function get_object_vars;
use function array_keys;

/**
 * Model
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
abstract class Model
{
	/**
	 * Container
	 *
	 * @var Container
	 */
	protected Container $_container;
	
	/**
	 * Application
	 *
	 * @var Application
	 */
	protected Application $_app;
	
	/**
	 * Config
	 *
	 * @var ArrayObject
	 */
	protected ArrayObject $_config;
	
	/**
	 */
	public function __construct()
	{
		$this->__unserialize();
	}
	
	/**
	 * @return array
	 */
	public function __serialize(): array
	{
		$properties = get_object_vars($this);
		unset($properties['_app']);
		unset($properties['_config']);
		
		return array_keys($properties);
	}
	
	/**
	 * @param array $data
	 */
	public function __unserialize(array $data = []): void
	{
		$this->_container = container();
		$this->_app = $this->_container->get(Application::class);
		$this->_config = $this->_app->getConfig();
	}
	
	/**
	 * @return string
	 */
	abstract public static function getStoreClass(): string;
}
