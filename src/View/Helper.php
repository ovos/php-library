<?php
declare(strict_types=1);

namespace Ovos\View;

use Ovos\Container;
use Ovos\Application;

use function Ovos\container;

/**
 * Helper
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Helper
{
	protected Container $container;
	
	protected Application $app;
	
	public function __construct()
	{
		$this->container = container();
		$this->app = $this->container->get(Application::class);
	}
}
