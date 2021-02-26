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
	 * @var ReflectionClass
	 */
	public ReflectionClass $class;
	
	/**
	 * @var int
	 */
	public int $id;

	/**
	 * @param ReflectionClass $class
	 * @param int $id
	 */
	public function __construct(ReflectionClass $class, int $id)
	{
		$this->class = $class;
		$this->id = $id;
	}

	/**
	 * @param string $direction
	 * 
	 * @return void
	 *
	 * @throws NotFoundException
	 */
	public function run(string $direction): void
	{
		$this->measurement = new Measurement;
		$this->measurement->start();
		
		/** @var Migration $migration */
		$migration = $this->class->newInstance($this->class);
		if(is_subclass_of($migration, 'Ovos\Migration') === false)
		{
			throw new NotFoundException('A class has to extend a "Ovos\Migration" class.');
		}
		
		$migration->{$direction}();
		
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

