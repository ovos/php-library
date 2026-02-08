<?php
declare(strict_types=1);

namespace Ovos\Container\Traits;

/**
 * TraitParameters
 *
 * @author Marcin Gil <mg@ovos.at>
 */
trait TraitParameters
{
	protected array $parameters = [];
	
	public function setParameters(
		array $parameters = [],
	): static
	{
		$this->parameters = $parameters;
		
		return $this;
	}
	
	public function getParameters(): array
	{
		return $this->parameters;
	}
}
