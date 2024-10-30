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
	/**
	 * @var Closure
	 */
	protected Closure $_callback;
	
	/**
	 * @param Closure $callback
	 *
	 * @return self
	 */
	public function setCallback(Closure $callback): self
	{
		$this->_callback = $callback;
		
		return $this;
	}
	
	/**
	 * @return Closure
	 */
	public function getCallback(): Closure
	{
		return $this->_callback;
	}
	
	/**
	 * @param Closure $callback
	 */
	public function __construct(Closure $callback)
	{
		$this->setCallback($callback);
	}
	
	/**
	 * @param mixed $value
	 *
	 * @return mixed
	 */
	public function filter(mixed $value): mixed
	{
		return ($this->_callback)($value);
	}
}
