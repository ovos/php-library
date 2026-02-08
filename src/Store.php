<?php
declare(strict_types=1);

namespace Ovos;

/**
 * Store
 *
 * @author Marcin Gil <mg@ovos.at>
 */
abstract class Store
{
	protected Container $container;
	
	public function __construct()
	{
		$this->container = container();
	}
}
