<?php
declare(strict_types=1);

namespace Ovos;

use Ovos\Container\Inject;
use Ovos\Test\Internal;

/**
 * Test
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
abstract class Test
{
	#[Inject]
	protected Container $container;
	
	#[Inject]
	protected Application $app;
	
	#[Inject]
	protected ArrayObject $config;
	
	protected bool $disabled = false;
	
	protected ?string $reason = null;
	
	#[Internal]
	public function setDisabled
	(
		bool $disabled,
		?string $reason = null,
	): static
	{
		$this->disabled = $disabled;
		$this->reason = $reason;
		
		return $this;
	}
	
	#[Internal]
	public function isDisabled(): bool
	{
		return $this->disabled;
	}
	
	#[Internal]
	public function getReason(): ?string
	{
		return $this->reason;
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
