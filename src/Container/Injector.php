<?php
declare(strict_types=1);

namespace Ovos\Container;

use Ovos\Container;

/**
 * Injector
 *
 * @author Marcin Gil <mg@ovos.at>
 */
abstract class Injector
{
	protected bool $transient = false;
	
	abstract public function inject(
		Container $container,
	): object;
	
	public function setTransient(
		bool $transient,
	): static
	{
		$this->transient = $transient;
		
		return $this;
	}
	
	public function isTransient(): bool
	{
		return $this->transient;
	}
}
