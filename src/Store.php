<?php
declare(strict_types=1);

namespace Ovos;

/**
 * Store
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
abstract class Store
{
	/**
	 * Application
	 *
	 * @var Application
	 */
	protected Application $_app;

	/**
	 */
	public function __construct()
	{
		$this->_app = app();
	}
}
