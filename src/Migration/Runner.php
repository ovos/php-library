<?php
declare(strict_types=1);

namespace Ovos\Migration;

use Ovos\Migration;
use Ovos\Measurement;
use Ovos\ArrayObject;
use Ovos\Exception\NotFoundException;
use ReflectionClass;
use ReflectionMethod;

/**
 * Runner
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
class Runner
{
	/**
	 * @var int
	 */
	public int $id;
	
	/**
	 * @var ReflectionClass
	 */
	public ReflectionClass $class;

	/**
	 * @param int $id
	 * @param ReflectionClass $class
	 */
	public function __construct(int $id, ReflectionClass $class)
	{
		$this->id = $id;
		$this->class = $class;
	}

	/**
	 * @return void
	 *
	 * @throws NotFoundException
	 */
	public function run(): void
	{
		$this->measurement = new Measurement;
		$this->measurement->start();
		
		/** @var Migration $migration */
		$migration = $this->class->newInstance($this->class);
		if(is_subclass_of($migration, 'Ovos\Migration') === false)
		{
			throw new NotFoundException('A class has to extend a "Ovos\Migration" class.');
		}
		
		$migration->up();
		
		$this->measurement->stop();
	}

	/**
	 * @return string
	 */
	public function __toString()
	{
		return $this->class->name;
	}
}

