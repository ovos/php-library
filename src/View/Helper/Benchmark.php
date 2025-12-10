<?php
declare(strict_types=1);

namespace Ovos\View\Helper;

use Ovos\Measurement;
use Ovos\View;
use Ovos\View\Helper;
use Ovos\Service;

/**
 * Benchmark
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
class Benchmark extends Helper
{
	public function getBenchmark(): ?Service\Benchmark
	{
		static $benchmark;
		if($benchmark === null)
		{
			$benchmark = $this->container->get(Service\Benchmark::SYMBOL);
			$benchmark->stop();
		}
		
		return $benchmark;
	}
	
	public function getTotal(): ?Measurement
	{
		if(($benchmark = $this->getBenchmark()) === null)
		{
			return null;
		}
		
		return $benchmark->getMeasurements()['total'];
	}
	
	public function __toString(): string
	{
		if(($benchmark = $this->getBenchmark()) === null)
		{
			return '';
		}
		
		$view = new View('helpers/benchmark.phtml');
		$view->measurements = $benchmark->getMeasurements();
		
		return $view->__toString();
	}
}
