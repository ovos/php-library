<?php
declare(strict_types=1);

namespace Ovos\Migration;

use Ovos\Migration;
use Ovos\Measurement;
use Ovos\Exception\InvalidException\InvalidClassException;
use ReflectionClass;

/**
 * Runner
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
class Runner
{
	public ReflectionClass $class;
	
	public int $id;
	
	public ?Measurement $measurement = null;
	
	public function __construct(ReflectionClass $class,
		int $id,
	)
	{
		$this->class = $class;
		$this->id = $id;
	}
	
	public function run(
		string $direction,
	): void
	{
		$this->measurement = new Measurement;
		$this->measurement->start();
		
		/** @var Migration $migration */
		$migration = $this->class->newInstance($this->class);
		if($migration instanceof Migration === false)
		{
			throw new InvalidClassException('A class has to extend an "Ovos\Migration" class.');
		}
		
		$migration->{$direction}();
		
		$this->measurement->stop();
	}
	
	public function __toString(): string
	{
		return $this->class->name;
	}
}

