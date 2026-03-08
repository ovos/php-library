<?php
declare(strict_types=1);

namespace Ovos\Service;

use Ovos\ArrayObject;
use Ovos\Container\Inject;
use Ovos\Container\ArrayObject as InjectArrayObject;
use Ovos\Exception;
use Ovos\Measurement;
use Ovos\Service;

/**
 * Benchmark
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Benchmark extends Service
{
	public const string SYMBOL = 'benchmark';
	
	public const string TOTAL = 'total';
	
	#[Inject('config')]
	#[InjectArrayObject('system', 'profilers')]
	protected ?ArrayObject $profilers = null;
	
	/**
	 * @var Measurement[]
	 */
	protected array $measurements = [];
	
	public function __construct()
	{
		$this->start();
	}
	
	public function start(
		string $name = self::TOTAL,
	): static
	{
		if($this->profilers !== null
			&& $this->profilers->enabled === false)
		{
			return $this;
		}
		
		$this->measurements[$name] = new Measurement;
		$this->measurements[$name]->start();
		
		return $this;
	}
	
	public function stop(
		string $name = self::TOTAL,
	): static
	{
		if(isset($this->measurements[$name]) === false)
		{
			return $this;
		}
		
		$this->measurements[$name]->stop();
		
		return $this;
	}
	
	public function get(
		string $name = self::TOTAL,
	): Measurement
	{
		if(isset($this->measurements[$name]) === false)
		{
			throw new Exception('Please use start() first.');
		}
		
		return $this->measurements[$name];
	}
	
	/**
	 * @return Measurement[]
	 */
	public function getMeasurements(): array
	{
		return $this->measurements;
	}
}
