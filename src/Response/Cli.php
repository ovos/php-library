<?php
declare(strict_types=1);

namespace Ovos\Response;

/**
 * Cli
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Cli extends Html
{
	protected bool $coloredOutput = true;
	
	public function __construct(
		?string $response = null,
	)
	{
		parent::__construct();
		
		$this->set($response);
	}
	
	public function setColoredOutput(
		bool $coloredOutput,
	): static
	{
		$this->coloredOutput = $coloredOutput;
		
		return $this;
	}
	
	public function getColoredOutput(): bool
	{
		return $this->coloredOutput;
	}
}
