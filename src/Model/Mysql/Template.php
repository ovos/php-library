<?php
declare(strict_types=1);

namespace Ovos\Model\Mysql;

use Ovos\Model\Mysql;
use Ovos\Application;
use Ovos\ArrayObject;

use function Ovos\app;

/**
 * Template
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
abstract class Template
{
	/**
	 * Application
	 *
	 * @var Application
	 */
	protected null|Application $_app = null;

	/**
	 * Config
	 *
	 * @var ArrayObject
	 */
	protected null|ArrayObject $_config = null;

	/**
	 */
	public function __construct()
	{
		$this->__wakeup();
	}

	/**
	 * @return array
	 */
	public function __sleep(): array
	{
		$properties = get_object_vars($this);
		unset($properties['_app']);
		unset($properties['_config']);
		
		return array_keys($properties);
	}

	/**
	 */
	public function __wakeup()
	{
		$this->_app = app();
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
