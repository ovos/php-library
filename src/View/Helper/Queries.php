<?php
declare(strict_types=1);

namespace Ovos\View\Helper;

use Ovos\Arrays;
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
	 * @return string
	 */
	public function __toString(): string
	{
		if($this->_app->getConfig()->system->profilers->enabled === false)
		{
			return '';
		}
	
		$reporter = new Reporter;
		$report = $reporter->getReport();
		if(empty($report))
		{
			return '';
		}

		$view = new View('helpers/queries.phtml');
		$view->queries = Arrays::deepToArrayObject($report);

		return $view->__toString();
	}
}