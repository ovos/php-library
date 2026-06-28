<?php
declare(strict_types=1);

namespace Ovos\Test;

use Ovos\Container;
use Ovos\Container\Inject;
use Ovos\Test;
use Ovos\Test\Exception\SkipException;
use Ovos\Exception\InvalidException\InvalidClassException;
use ReflectionClass;
use ReflectionMethod;
use Throwable;

use function array_map;
use function count;
use function in_array;
use function is_bool;

/**
 * Runner
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Runner
{
	#[Inject]
	protected Container $container;
	
	public const string METHOD_ATTRIBUTE_INTERNAL = Internal::class;
	
	public ReflectionClass $class;
	
	protected ?Test $test = null;
	
	protected array $testMethods = [];
	
	/**
	 * @var Result[] 
	 */
	protected array $results = [];
	
	protected ?string $filterClass = null;
	
	protected ?string $filterMethod = null;
	
	public function __construct(
		ReflectionClass $class,
	)
	{
		$this->class = $class;
		$this->testMethods = $this->getTestMethods();
		
		foreach($this->testMethods as $method)
		{
			$this->results[] = new Result($class, $method);
		}
	}
	
	public function filterClass(
		?string $filterClass,
	): static
	{
		$this->filterClass = $filterClass;
		
		return $this;
	}
	
	public function filterMethod(
		?string $filterMethod,
	): static
	{
		$this->filterMethod = $filterMethod;
		
		return $this;
	}
	
	/**
	 * @return Result[]
	 */
	public function run(): array
	{
		// skip the entire class
		if($this->filterClass !== null
			&& $this->filterClass !== $this->getClassName()
		)
		{
			foreach($this->results as $result)
			{
				// set SKIPPED on all results
				$result->setResult(Result::RESULT_SKIPPED);
			}
			
			return $this->results;
		}
		
		// construct
		try
		{
			$test = $this->getTestInstance();
		}
		catch(Throwable $throwable)
		{
			foreach($this->results as $result)
			{
				$result->setThrowable($throwable);
				// set FAILED on all results
				$result->setResult(Result::RESULT_FAILED);
			}
			
			return $this->results;
		}
		
		// if the test is disabled, set SKIPPED on all results
		if($test->isDisabled())
		{
			foreach($this->results as $result)
			{
				$result->setResult(Result::RESULT_SKIPPED);
				$result->setReason($test->getReason());
			}
			
			return $this->results;
		}
		
		// instance successfully constructed, 
		// each public method will have its own result
		foreach($this->results as $result)
		{
			// skip the method, only if the class is not specified or matches the current class
			if($this->filterMethod !== null
				&& $this->filterMethod !== $result->getMethodName()
			)
			{
				$result->setResult(Result::RESULT_SKIPPED);
				
				continue;
			}
			
			// progress - announce the method about to run (a no-op unless the
			// test opts in; benchmarks print the running method here)
			$test->progress($result->getMethodName());
			
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
			foreach($this->results as $result)
			{
				$result->setThrowable($throwable);
				$result->setResult(Result::RESULT_FAILED);
			}
			
			return $this->results;
		}
		
		return $this->results;
	}
	
	public function getTestInstance(): Test
	{
		if($this->test === null)
		{
			/** @var Test $test */
			$test = $this->container
				->injectClass($this->class->name);
			
			if($test instanceof Test === false)
			{
				throw new InvalidClassException(
					'A class has to extend a "Ovos\Test" class.');
			}
			
			$this->test = $test;
		}
		
		return $this->test;
	}
	
	protected function getTestMethods(): array
	{
		$methods = [];
		$publicMethods = $this->class
			->getMethods(ReflectionMethod::IS_PUBLIC);
		
		foreach($publicMethods as $method)
		{
			if($method->isConstructor()
				|| $method->isDestructor()
				|| $method->isStatic()
			)
			{
				continue;
			}
			
			$methodAttributes = $method->getAttributes();
			$methodAttributesArray = array_map(
				static fn($attribute) => $attribute->getName(), $methodAttributes
			);
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
		return count($this->results);
	}
	
	/**
	 * @return Result[]
	 */
	public function getResults(): array
	{
		return $this->results;
	}
	
	public function getClassName(): string
	{
		return $this->class->name;
	}
	
	public function __toString()
	{
		return $this->class->name;
	}
}

