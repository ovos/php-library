<?php
declare(strict_types=1);

namespace Ovos\Form\Validator;

use Ovos\Form\Error;
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
	 * Errors
	 */
	public const string ERROR_CALLBACK = 'callback';
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
		$valid = ($this->_callback)($value);
		
		if($valid === false)
		{
			$error = new Error(self::ERROR_CALLBACK, sprintf($this->getMessage(self::ERROR_CALLBACK),
				$this->getElement()->getName()
			));
			$this->addError($error);
		}
		
		return $valid;
	}
}
