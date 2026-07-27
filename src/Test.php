<?php
declare(strict_types=1);

namespace Ovos;

use Ovos\Container\Inject;
use Ovos\Terminal\Highlighter;
use Ovos\Test\Internal;

/**
 * Test
 *
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
	 * Called by the runner before each measured method: announces the test
	 * about to run as a transient, in-place status line, so a long run shows
	 * only the current test instead of a growing list. The controller clears
	 * the line before printing the results table.
	 *
	 * Colored to match the results table's first column, so the eye follows the
	 * same shape from the live line into the table.
	 */
	#[Internal]
	public function progress(
		string $method,
	): void
	{
		Terminal::status(
			Highlighter::className(static::class . '::' . $method),
			markup: true,
		);
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
