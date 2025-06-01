<?php
declare(strict_types=1);

namespace Ovos\Container\Traits;

use Closure;

/**
 * TraitInitializer
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
trait TraitInitializer
{
	/**
	 * @var ?Closure
	 */
	protected ?Closure $_initializer = null;
	
	/**
	 * @param ?callable $initializer
	 *
	 * @return self
	 */
	public function setInitializer(?callable $initializer): self
	{
		$this->_initializer = $initializer;
		
		return $this;
	}
	
	/**
	 * @return ?Closure
	 */
	public function getInitializer(): ?Closure
	{
		return $this->_initializer;
	}
}
