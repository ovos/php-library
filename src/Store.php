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
	protected Container $container;
	
	protected Application $app;
	
	public function __construct()
	{
		$this->container = container();
		$this->app = $this->container->get(Application::class);
	}
}
