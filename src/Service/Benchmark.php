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
	public const SYMBOL = 'benchmark';

	/**
	 * @var string
	 */
	public const TOTAL = 'total';

	/**
	 * @var Measurement[]
	 */
	protected array $_measurements = [];

	/**
	 * @return string
	 */
	public function getSymbol(): string
	{
		return self::SYMBOL;
	}

	/**
	 */
	public function __construct()
	{
		parent::__construct();

		$this->start();
	}

	/**
	 * @param string $name
	 *
	 * @return $this
	 */
	public function start(string $name = self::TOTAL): self
	{
		$this->_measurements[$name] = new Measurement;
		$this->_measurements[$name]->start();

		return $this;
	}

	/**
	 * @param string $name
	 *
	 * @return $this
	 */
	public function stop(string $name = self::TOTAL): self
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
		if(!isset($this->_measurements[$name]))
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
