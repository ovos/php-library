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
	 */
	public function __construct()
	{
		$this->_container = container();
		$this->_app = $this->_container->get(Application::class);
	}
}
