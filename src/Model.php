<?php
declare(strict_types=1);

namespace Ovos;

use Ovos\Store;

/**
 * Model
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
abstract class Model
{
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
	 * @return string
	 */
	abstract public static function getStoreClass(): string;
}
