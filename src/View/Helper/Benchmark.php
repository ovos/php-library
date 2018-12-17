<?php
declare(strict_types=1);

namespace Ovos\View\Helper;

use Ovos\View;
use Ovos\View\Helper;
use Ovos\Service;
use function Ovos\services;

/**
 * Benchmark
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
class Benchmark extends Helper
{
	/**
	 * @return string
	 */
	public function __toString(): string
	{
		$benchmark = services()->benchmark;
		/** @var Service\Benchmark $benchmark */
		if($benchmark === null)
		{
			return '';
		}

		$view = new View('helpers/benchmark.phtml');
		$benchmark->stop();
		$view->measurements = $benchmark->getMeasurements();

		return $view->__toString();
	}
}