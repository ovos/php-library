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
	 * @return string
	 *
	 * @throws NotFoundException
	 */
	public function run(): string
	{
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
			
			// prepare - called before each test method
			if($this->class->hasMethod('prepare'))
			{
				$prepare = $this->class->getMethod('prepare');
				$prepare->invoke($test);
			}
				
			$this->measurement = new Measurement;
			$this->measurement->start();
			
			$invokeResult = $this->method->invoke($test);
			
			$this->measurement->stop();
			
			if(is_bool($invokeResult))
			{
				$test->result = $invokeResult
					? Test::RESULT_PASSED
					: Test::RESULT_FAILED;
			}
			// test may be also completed without setting status as passed/failed
			else
			{
				$test->result = Test::RESULT_COMPLETED;
			}
			
			// finalize - called after each test method
			if($this->class->hasMethod('finalize'))
			{
				$finalize = $this->class->getMethod('finalize');
				$finalize->invoke($test);
			}
		}
		catch(Throwable $throwable)
		{
			// catch for later (see below)
			// & assign for the reporter
			$test->result = Test::RESULT_FAILED;
			$this->throwable = $throwable;
			
			throw $throwable;
		}
		
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

