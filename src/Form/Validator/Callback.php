<?php
declare(strict_types=1);

namespace Ovos\Form\Validator;

use Ovos\Form\Validator;
use Closure;

/**
 * Callback
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
class Callback extends Validator
{
	/**#@+
	 * Error constants
	 */
	public const ERROR_CALLBACK = 'callback';
	/**#@-*/

	/**
	 * @var Closure
	 */
	protected Closure $_callback;

	/**
	 * @param Closure $callback
	 */
	public function __construct(Closure $callback)
	{
		$this->setCallback($callback);
	}

	/**
	 * @param Closure $callback
	 * 
	 * @return self
	 */
	public function setCallback(Closure $callback): self
	{
		$this->_callback = $callback->bindTo($this, $this);
		
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
	 * @param null|mixed $value
	 *
	 * @return bool
	 */
	public function isValid(mixed $value): bool
	{
		return ($this->_callback)($value);
	}
}
