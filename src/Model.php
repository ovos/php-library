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
	protected $_app;

	/**
	 * Config
	 *
	 * @var ArrayObject
	 */
	protected $_config;

	/**
	 */
	public function __construct()
	{
		$this->_app = app();
		$this->_config = $this->_app->getConfig();
	}

	/**
	 * @return string
	 */
	abstract public static function getStoreClass(): string;
}
