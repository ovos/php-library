<?php
declare(strict_types=1);

namespace Ovos\View\Helper;

use Ovos\Console as BaseConsole;
use Ovos\View;
use Ovos\View\Helper;

/**
 * Console
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
class Console extends Helper
{
	/**
	 * @return int
	 */
	public function getCount(): int
	{
		return BaseConsole::getInstance()->getCount();
	}
	
	/**
	 * @return string
	 */
	public function __toString(): string
	{
		if($this->_app->getConfig()->system->profilers->enabled === false)
		{
			return '';
		}
		
		$messages = BaseConsole::getInstance()->getReport();
		if(empty($messages))
		{
			return '';
		}
		
		$view = new View('helpers/console.phtml');
		$view->messages = $messages;
		
		return $view->__toString();
	}
}
