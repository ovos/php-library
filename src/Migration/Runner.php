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
	
	/**
	 * What this migration is called in the `migrations` table. Null for a
	 * migration with a class of its own, whose class name is the answer; a
	 * SQL-only migration has no class to be named after, so the runner
	 * hands over the name it would have had.
	 */
	public ?string $name = null;
	
	/**
	 * Where the SQL halves live, for a SQL-only migration.
	 */
	public ?string $sqlBase = null;
	
	public function __construct(ReflectionClass $class,
		int $id,
		?string $name = null,
		?string $sqlBase = null,
	)
	{
		$this->class = $class;
		$this->id = $id;
		$this->name = $name;
		$this->sqlBase = $sqlBase;
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
				['class' => $this->class, 'sqlBase' => $this->sqlBase],
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
		return $this->name ?? $this->class->name;
	}
}

