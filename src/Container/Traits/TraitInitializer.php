<?php
declare(strict_types=1);

namespace Ovos\Container\Traits;

use Closure;

/**
 * TraitInitializer
 *
 * @author Marcin Gil <mg@ovos.at>
 */
trait TraitInitializer
{
	protected ?Closure $initializer = null;
	
	public function setInitializer(
		?callable $initializer,
	): static
	{
		$this->initializer = $initializer;
		
		return $this;
	}
	
	public function getInitializer(): ?Closure
	{
		return $this->initializer;
	}
}
