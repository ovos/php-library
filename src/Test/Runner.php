<?php
declare(strict_types=1);

namespace Ovos\Test;

use Ovos\Measurement;
use Ovos\Test;
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
	 * @var ReflectionMethod
	 */
	public ReflectionMethod $method;

	/**
	 * @var null|Measurement
	 */
	public null|Measurement $measurement = null;

	/**
	 * @var null|array
	 */
	public null|array $metricsEnd = null;

	/**
	 * @var null|bool
	 */
	public null|bool $result = null;

	/**
	 * @param ReflectionClass $class
	 * @param ReflectionMethod $method
	 */
	public function __construct(ReflectionClass $class, 
		ReflectionMethod $method)
	{
		$this->class = $class;
		$this->method = $method;
	}

	/**
	 * @return bool
	 *
	 * @throws NotFoundException
	 */
	public function run(): bool
	{
		$this->measurement = new Measurement;
		$this->measurement->start();
		
		/** @var Test $test */
		$test = $this->class->newInstance();
		if(is_subclass_of($test, 'Ovos\Test') === false)
		{
			throw new NotFoundException('A class has to extend a "Ovos\Test" class.');
		}
		
		$this->result = (bool) $this->method->invoke($test);
		$this->measurement->stop();

		return $this->result;
	}

	/**
	 * @return string
	 */
	public function __toString()
	{
		return $this->method->class . '::' . $this->method->name;
	}
}

