<?php
declare(strict_types=1);

namespace Ovos\View\Helper;

use Ovos\Console as BaseConsole;
use Ovos\View;
use Ovos\View\Helper;

/**
 * Console
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Console extends Helper
{
	public function getCount(): int
	{
		return $this->container
			->getClass(BaseConsole::class)
			->getCount();
	}
	
	public function __toString(): string
	{
		if($this->app->getConfig()->system->profilers->enabled === false)
		{
			return '';
		}
		
		$messages = $this->container
			->getClass(BaseConsole::class)
			->getReport();
		if(empty($messages))
		{
			return '';
		}
		
		$view = new View('helpers/console.phtml');
		$view->messages = $messages;
		
		return $view->__toString();
	}
}
