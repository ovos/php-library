<?php
declare(strict_types=1);

namespace Ovos\Test;

use Ovos\Measurement;
use ReflectionClass;
use ReflectionMethod;
use Throwable;

/**
 * Result
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
class Result
{
	/**
	 * @var ReflectionClass
	 */
	public ReflectionClass $class;
	
	/**
	 * @var ReflectionMethod
	 */
	public ReflectionMethod $method;
	
	/**#@+
	 * Results
	 */
	public const string RESULT_COMPLETED = 'completed';
	public const string RESULT_PASSED = 'passed';
	public const string RESULT_FAILED = 'failed';
	public const string RESULT_SKIPPED = 'skipped';
	/**#@-*/
	
	/**
	 * @var string
	 */
	public string $result = self::RESULT_COMPLETED;
	
	/**
	 * @var ?string
	 */
	public ?string $reason = null;
	
	/**
	 * @var ?Throwable 
	 */
	public ?Throwable $throwable = null;
	
	/**
	 * @var ?Measurement
	 */
	public ?Measurement $measurement = null;
	
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
	 * @param string $result
	 *
	 * @return static
	 */
	public function setResult(string $result): static
	{
		$this->result = $result;
		
		return $this;
	}
	
	/**
	 * @return string
	 */
	public function getResult(): string
	{
		return $this->result;
	}
	
	/**
	 * @param ?string $reason
	 *
	 * @return static
	 */
	public function setReason(?string $reason): static
	{
		$this->reason = $reason;
		
		return $this;
	}
	
	/**
	 * @return ?string
	 */
	public function getReason(): ?string
	{
		return $this->reason;
	}
	
	/**
	 * @param Throwable $throwable
	 *
	 * @return static
	 */
	public function setThrowable(Throwable $throwable): static
	{
		$this->throwable = $throwable;
		
		return $this;
	}
	
	/**
	 * @return static
	 */
	public function startMeasurement(): static
	{
		$this->measurement = new Measurement;
		$this->measurement->start();
		
		return $this;
	}
	
	/**
	 * @return static
	 */
	public function stopMeasurement(): static
	{
		$this->measurement->stop();
		
		return $this;
	}
	
	/**
	 * @return ?Measurement
	 */
	public function getMeasurement(): ?Measurement
	{
		return $this->measurement;
	}
	
	/**
	 * @return string
	 */
	public function getClassName(): string
	{
		return $this->method->class;
	}
	
	/**
	 * @return string
	 */
	public function getMethodName(): string
	{
		return $this->method->name;
	}
	
	/**
	 * @return string
	 */
	public function __toString()
	{
		return $this->method->class . '::' . $this->method->name;
	}
}

