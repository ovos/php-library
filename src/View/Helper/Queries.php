<?php
declare(strict_types=1);

namespace Ovos\View\Helper;

use Ovos\Pdo\Profiler\Reporter;
use Ovos\View;
use Ovos\View\Helper;

/**
 * Queries
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
class Queries extends Helper
{
	/**
	 * @return Reporter
	 */
	public function getReporter(): Reporter
	{
		static $reporter;
		if($reporter === null)
		{
			$reporter = new Reporter;
		}
		
		return $reporter;
	}
	
	/**
	 * @return int
	 */
	public function getCount(): int
	{
		return $this->getReporter()->getCount();
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
		
		$report = $this->getReporter()->getReport();
		if(empty($report))
		{
			return '';
		}
		
		$view = new View('helpers/queries.phtml');
		$view->queries = $report;
		
		return $view->__toString();
	}
}
