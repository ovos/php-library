<?php
declare(strict_types=1);

namespace Ovos\View;

use Ovos\Container;
use Ovos\Application;

use function Ovos\container;

/**
 * Helper
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
class Helper
{
	/**
	 * @var Container
	 */
	protected Container $_container;
	
	/**
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
