<?php
declare(strict_types=1);

namespace Ovos;

use Ovos\Test\Internal;

/**
 * Test
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
abstract class Test
{
	/**
	 * @var bool
	 */
	protected bool $_isDisabled = false;
	
	/**
	 * @var ?string
	 */
	protected ?string $_reason = null;
	
	/**
	 * @param bool $isDisabled
	 * @param ?string $reason
	 *
	 * @return self
	 */
	#[Internal]
	public function setIsDisabled
	(
		bool $isDisabled,
		?string $reason = null,
	): self
	{
		$this->_isDisabled = $isDisabled;
		$this->_reason = $reason;
		
		return $this;
	}
	
	/**
	 * @return bool
	 */
	#[Internal]
	public function isDisabled(): bool
	{
		return $this->_isDisabled;
	}
	
	/**
	 * @return ?string
	 */
	#[Internal]
	public function getReason(): ?string
	{
		return $this->_reason;
	}
	
	/**
	 * Called by the runner before each test method
	 */
	#[Internal]
	public function prepare(): void
	{
	}
	
	/**
	 * Called by the runner after each test method
	 */
	#[Internal]
	public function finalize(): void
	{
	}
	
	/**
	 * Called by the runner after all test methods have been invoked
	 */
	#[Internal]
	public function deconstruct(): void
	{
	}
}
