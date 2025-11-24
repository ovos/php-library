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
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
abstract class Template
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
	 * @param Mysql $model
	 */
	public function setUp(Mysql $model): void
	{
	}
	
	/**
	 * @param Mysql $model
	 */
	public function preInsert(Mysql $model): void
	{
	}
	
	/**
	 * @param Mysql $model
	 */
	public function preUpdate(Mysql $model): void
	{
	}
	
	/**
	 * @param Mysql $model
	 */
	public function preSave(Mysql $model): void
	{
	}
	
	/**
	 * @param Mysql $model
	 */
	public function preDelete(Mysql $model): void
	{
	}
}
