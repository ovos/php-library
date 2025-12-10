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
	public ReflectionClass $class;
	
	public ReflectionMethod $method;
	
	// Results
	public const string RESULT_COMPLETED = 'completed';
	public const string RESULT_PASSED = 'passed';
	public const string RESULT_FAILED = 'failed';
	public const string RESULT_SKIPPED = 'skipped';
	
	public string $result = self::RESULT_COMPLETED;
	
	public ?string $reason = null;
	
	public ?Throwable $throwable = null;
	
	public ?Measurement $measurement = null;
	
	public function __construct(
		ReflectionClass $class,
		ReflectionMethod $method,
	)
	{
		$this->class = $class;
		$this->method = $method;
	}
	
	public function setResult(
		string $result,
	): static
	{
		$this->result = $result;
		
		return $this;
	}
	
	public function getResult(): string
	{
		return $this->result;
	}
	
	public function setReason(
		?string $reason,
	): static
	{
		$this->reason = $reason;
		
		return $this;
	}
	
	public function getReason(): ?string
	{
		return $this->reason;
	}
	
	public function setThrowable(
		Throwable $throwable,
	): static
	{
		$this->throwable = $throwable;
		
		return $this;
	}
	
	public function startMeasurement(): static
	{
		$this->measurement = new Measurement;
		$this->measurement->start();
		
		return $this;
	}
	
	public function stopMeasurement(): static
	{
		$this->measurement->stop();
		
		return $this;
	}
	
	public function getMeasurement(): ?Measurement
	{
		return $this->measurement;
	}
	
	public function getClassName(): string
	{
		return $this->method->class;
	}
	
	public function getMethodName(): string
	{
		return $this->method->name;
	}
	
	public function __toString()
	{
		return $this->method->class . '::' . $this->method->name;
	}
}

