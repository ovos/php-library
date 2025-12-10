<?php
declare(strict_types=1);

namespace Ovos\Form\Filter;

use Ovos\Form\Filter;
use Closure;

/**
 * Callback
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
class Callback extends Filter
{
	protected Closure $callback;
	
	public function __construct(
		Closure $callback,
	)
	{
		$this->setCallback($callback);
	}
	
	public function setCallback(
		Closure $callback,
	): static
	{
		$this->callback = $callback;
		
		return $this;
	}
	
	public function getCallback(): Closure
	{
		return $this->callback;
	}
	
	public function filter(
		mixed $value,
	): mixed
	{
		return ($this->callback)($value);
	}
}
