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
	 * @return self
	 */
	public function setResult(string $result): self
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
	 * @return self
	 */
	public function setReason(?string $reason): self
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
	 * @return self
	 */
	public function setThrowable(Throwable $throwable): self
	{
		$this->throwable = $throwable;
		
		return $this;
	}
	
	/**
	 * @return self
	 */
	public function startMeasurement(): self
	{
		$this->measurement = new Measurement;
		$this->measurement->start();
		
		return $this;
	}
	
	/**
	 * @return self
	 */
	public function stopMeasurement(): self
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

