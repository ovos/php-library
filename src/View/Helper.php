<?php
declare(strict_types=1);

namespace Ovos\View;

use Ovos\Container;
use Ovos\Application;
use Ovos\Response;
use Ovos\Terminal;

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
	
	/**
	 * Whether terminal output rendered by this helper may carry <color> markup.
	 * A CLI response answers for itself (it can be pinned either way), anything
	 * else falls back to environment detection.
	 */
	protected function usesMarkup(): bool
	{
		$response = $this->app->getResponse();
		
		if($response instanceof Response\Cli)
		{
			return $response->getColoredOutput();
		}
		
		return Terminal::supportsColor();
	}
}
