<?php
declare(strict_types=1);

namespace Ovos\Test;

use Ovos\Test;
use Ovos\Test\Exception\SkipException;
use Ovos\Exception\InvalidException\InvalidClassException;
use ReflectionClass;
use ReflectionMethod;
use Throwable;

use function count;
use function array_map;
use function in_array;
use function is_subclass_of;
use function is_bool;

/**
 * Runner
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
class Runner
{
	/**
	 * @var string
	 */
	public const string METHOD_ATTRIBUTE_INTERNAL = Internal::class;
	
	/**
	 * @var ReflectionClass
	 */
	public ReflectionClass $class;
	
	/**
	 * @var ?Test 
	 */
	protected ?Test $_test = null;
	
	/**
	 * @var array
	 */
	protected array $_testMethods = [];
	
	/**
	 * @var Result[] 
	 */
	protected array $_results = [];
	
	/**
	 * @var ?string
	 */
	protected ?string $_filterClass = null;
	
	/**
	 * @var ?string
	 */
	protected ?string $_filterMethod = null;
	
	/**
	 * @param ReflectionClass $class
	 */
	public function __construct(ReflectionClass $class)
	{
		$this->class = $class;
		$this->_testMethods = $this->_getTestMethods();
		
		foreach($this->_testMethods as $method)
		{
			$this->_results[] = new Result($class, $method);
		}
	}
	
	/**
	 * @param ?string $filterClass
	 *
	 * @return self
	 */
	public function filterClass(?string $filterClass): self
	{
		$this->_filterClass = $filterClass;
		
		return $this;
	}
	
	/**
	 * @param ?string $filterMethod
	 *
	 * @return self
	 */
	public function filterMethod(?string $filterMethod): self
	{
		$this->_filterMethod = $filterMethod;
		
		return $this;
	}
	
	/**
	 * @return Result[]
	 */
	public function run(): array
	{
		// skip the entire class
		if($this->_filterClass !== null
			&& $this->_filterClass !== $this->getClassName()
		)
		{
			foreach($this->_results as $result)
			{
				// set SKIPPED on all results
				$result->setResult(Result::RESULT_SKIPPED);
			}
			
			return $this->_results;
		}
		
		// construct
		try
		{
			$test = $this->getTestInstance();
		}
		catch(Throwable $throwable)
		{
			foreach($this->_results as $result)
			{
				$result->setThrowable($throwable);
				// set FAILED on all results
				$result->setResult(Result::RESULT_FAILED);
			}
			
			return $this->_results;
		}
		
		// if the test is disabled, set SKIPPED on all results
		if($test->isDisabled())
		{
			foreach($this->_results as $result)
			{
				$result->setResult(Result::RESULT_SKIPPED);
				$result->setReason($test->getReason());
			}
			
			return $this->_results;
		}
		
		// instance successfully constructed, 
		// each public method will have its own result
		foreach($this->_results as $result)
		{
			// skip the method, only if the class is not specified or matches the current class
			if($this->_filterMethod !== null
				&& $this->_filterMethod !== $result->getMethodName()
			)
			{
				$result->setResult(Result::RESULT_SKIPPED);
				
				continue;
			}
			
			// prepare - called before each test method
			if($this->class->hasMethod('prepare'))
			{
				$prepare = $this->class->getMethod('prepare');
				$prepare->invoke($test);
			}
			
			// call a single method
			try
			{
				$result->startMeasurement();
				try
				{
					$invokeResult = $result->method->invoke($test);
					
					if(is_bool($invokeResult))
					{
						$result->setResult($invokeResult
							? Result::RESULT_PASSED
							: Result::RESULT_FAILED
						);
					}
					// test may be also completed without setting status as passed/failed
					else
					{
						$result->setResult(Result::RESULT_COMPLETED);
					}
				}
				catch(SkipException $exception)
				{
					$result->setResult(Result::RESULT_SKIPPED);
					$result->setReason($exception->getMessage());
				}
			}
			catch(Throwable $throwable)
			{
				$result->setThrowable($throwable);
				$result->setResult(Result::RESULT_FAILED);
			}
			finally
			{
				$result->stopMeasurement();
			}
			
			// finalize - called after each test method
			if($this->class->hasMethod('finalize'))
			{
				$finalize = $this->class->getMethod('finalize');
				$finalize->invoke($test);
			}
		}
		
		// deconstruct
		try
		{
			$test->deconstruct();
		}
		catch(Throwable $throwable)
		{
			foreach($this->_results as $result)
			{
				$result->setThrowable($throwable);
				$result->setResult(Result::RESULT_FAILED);
			}
			
			return $this->_results;
		}
		
		return $this->_results;
	}
	
	/**
	 * @return Test
	 */
	public function getTestInstance(): Test
	{
		if($this->_test === null)
		{
			/** @var Test $test */
			$this->_test = ($test = $this->class->newInstance());
			
			if(is_subclass_of($this->_test , 'Ovos\Test') === false)
			{
				throw new InvalidClassException(
					'A class has to extend a "Ovos\Test" class.');
			}
		}
		
		return $this->_test;
	}
	
	/**
	 * @return array
	 */
	protected function _getTestMethods(): array
	{
		$methods = [];
		$publicMethods = $this->class
			->getMethods(ReflectionMethod::IS_PUBLIC);
		
		foreach($publicMethods as $method)
		{
			if($method->isConstructor()
				|| $method->isDestructor()
			)
			{
				continue;
			}
			
			$methodAttributes = $method->getAttributes();
			$methodAttributesArray = array_map(
				fn($attribute) => $attribute->getName(), $methodAttributes);
			if(in_array(
				self::METHOD_ATTRIBUTE_INTERNAL,
				$methodAttributesArray, 
				true
			))
			{
				continue;
			}
			
			$methods[] = $method;
		}
		
		return $methods;
	}
	
	public function getCount(): int
	{
		return count($this->_results);
	}
	
	/**
	 * @return Result[]
	 */
	public function getResults(): array
	{
		return $this->_results;
	}
	
	/**
	 * @return string
	 */
	public function getClassName(): string
	{
		return $this->class->name;
	}
	
	/**
	 * @return string
	 */
	public function __toString()
	{
		return $this->class->name;
	}
}

