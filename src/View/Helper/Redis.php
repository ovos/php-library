<?php
declare(strict_types=1);

namespace Ovos\View\Helper;

use Ovos\Redis\Profiler\Reporter;
use Ovos\View;
use Ovos\View\Helper;

/**
 * Redis
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Redis extends Helper
{
	public function getReporter(): Reporter
	{
		static $reporter;
		if($reporter === null)
		{
			$reporter = new Reporter;
		}

		return $reporter;
	}

	public function getCount(): int
	{
		return $this->getReporter()->getCount();
	}

	public function __toString(): string
	{
		if($this->app->getConfig()->system->profilers->enabled === false)
		{
			return '';
		}

		$report = $this->getReporter()->getReport();
		if(empty($report))
		{
			return '';
		}

		$view = new View('helpers/redis.phtml');
		$view->commands = $report;

		return $view->__toString();
	}
}
