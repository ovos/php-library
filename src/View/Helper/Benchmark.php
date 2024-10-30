<?php
declare(strict_types=1);

namespace Ovos\View\Helper;

use Ovos\Measurement;
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
	 * @return ?Service\Benchmark
	 */
	public function getBenchmark(): ?Service\Benchmark
	{
		static $benchmark;
		if($benchmark === null)
		{
			$benchmark = services()->benchmark;
			$benchmark->stop();
		}
		
		return $benchmark;
	}
	
	/**
	 * @return ?Measurement
	 */
	public function getTotal(): ?Measurement
	{
		if(($benchmark = $this->getBenchmark()) === null)
		{
			return null;
		}
		
		return $this->getBenchmark()->getMeasurements()['total'];
	}
	
	/**
	 * @return string
	 */
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
