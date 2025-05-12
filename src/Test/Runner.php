<?php
declare(strict_types=1);

namespace Ovos\Test;

use Ovos\Measurement;
use Ovos\Test;
use Ovos\Exception\NotFoundException;
use ReflectionClass;
use ReflectionMethod;
use Throwable;

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
	 * @var ?Measurement
	 */
	public ?Measurement $measurement = null;
	
	/**
	 * @var ?Test 
	 */
	public ?Test $test = null;
	
	/**
	 * @var ?Throwable 
	 */
	public ?Throwable $throwable = null;
	
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
	 * @return int
	 *
	 * @throws NotFoundException
	 */
	public function run(): int
	{
		$this->measurement = new Measurement;
		$this->measurement->start();
		
		$throwable = null;
		try
		{
			/** @var Test $test */
			$this->test = ($test = $this->class->newInstance());
			
			// if the test is disabled, skip it
			if($test->isDisabled())
			{
				return $test->result = Test::RESULT_SKIPPED;
			}
			
			if(is_subclass_of($test, 'Ovos\Test') === false)
			{
				throw new NotFoundException('A class has to extend a "Ovos\Test" class.');
			}
			
			if((bool)$this->method->invoke($test) === true)
			{
				$test->result = Test::RESULT_PASSED;
			}
		}
		catch(Throwable $throwable)
		{
			// catch for later (see below)
			// & assign for the reporter
			$this->throwable = $throwable;
		}
		
		// clean up
		if($this->class->hasMethod('cleanUp'))
		{
			$cleanUp = $this->class->getMethod('cleanUp');
			$cleanUp->invoke($test);
		}
		
		// throw after cleanup
		if($throwable)
		{
			throw $throwable;
		}
		
		$this->measurement->stop();
		
		return $test->result;
	}
	
	/**
	 * @return string
	 */
	public function __toString()
	{
		return $this->method->class . '::' . $this->method->name;
	}
}

