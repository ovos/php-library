<?php
declare(strict_types=1);

namespace Ovos\Service;

use Ovos\Exception;
use Ovos\Measurement;
use Ovos\Service;

/**
 * Benchmark
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
class Benchmark extends Service
{
	/**
	 * @var string
	 */
	public const string SYMBOL = 'benchmark';
	
	/**
	 * @var string
	 */
	public const string TOTAL = 'total';
	
	/**
	 * @var Measurement[]
	 */
	protected array $_measurements = [];
	
	/**
	 */
	public function __construct()
	{
		$this->start();
	}
	
	/**
	 * @param string $name
	 *
	 * @return static
	 */
	public function start(string $name = self::TOTAL): static
	{
		$this->_measurements[$name] = new Measurement;
		$this->_measurements[$name]->start();
		
		return $this;
	}
	
	/**
	 * @param string $name
	 *
	 * @return static
	 */
	public function stop(string $name = self::TOTAL): static
	{
		$this->get($name)->stop();
		
		return $this;
	}
	
	/**
	 * @param string $name
	 *
	 * @return Measurement
	 *
	 * @throws Exception
	 */
	public function get(string $name = self::TOTAL): Measurement
	{
		if(isset($this->_measurements[$name]) === false)
		{
			throw new Exception('Please use start() first.');
		}
		
		return $this->_measurements[$name];
	}
	
	/**
	 * @return Measurement[]
	 */
	public function getMeasurements(): array
	{
		return $this->_measurements;
	}
}
