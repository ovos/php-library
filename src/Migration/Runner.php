<?php
declare(strict_types=1);

namespace Ovos\Migration;

use Ovos\Container;
use Ovos\Container\Inject;
use Ovos\Migration;
use Ovos\Measurement;
use Ovos\Exception\InvalidException\InvalidClassException;
use ReflectionClass;

/**
 * Runner
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Runner
{
	#[Inject]
	protected Container $container;
	
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
		$migration = $this->container
			->injectClass(
				$this->class->name,
				['class' => $this->class],
			);
		
		if($migration instanceof Migration === false)
		{
			throw new InvalidClassException(
				'A class has to extend an "Ovos\Migration" class.'
			);
		}
		
		$migration->{$direction}();
		
		$this->measurement->stop();
	}
	
	public function __toString(): string
	{
		return $this->class->name;
	}
}

