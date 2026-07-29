<?php
declare(strict_types=1);

namespace Ovos\Form\Validator;

use Ovos\Form\Error;
use Ovos\Form\Validator;
use Closure;
use ReflectionFunction;

/**
 * Callback
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Callback extends Validator
{
	// Errors
	public const string ERROR_CALLBACK = 'callback';
	
	/**
	 * Overwrite with setMessage() for a task-specific text; without this
	 * default a failing callback used to FATAL on sprintf(null) instead of
	 * producing a form error
	 */
	protected array $messages = [
		self::ERROR_CALLBACK => '"%s" is not valid.',
	];
	
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
		// the rebinding is what lets a closure call $this->setMessage(...) for
		// a dynamic error text — but a STATIC closure cannot be rebound, and
		// `static fn` is the ordinary way to write a callback that needs no
		// $this. Binding unconditionally turned that idiom into a warning
		// today and an error in PHP 9.
		$this->callback = (new ReflectionFunction($callback))->isStatic()
			? $callback
			: $callback->bindTo($this, $this);
		
		return $this;
	}
	
	public function getCallback(): Closure
	{
		return $this->callback;
	}
	
	public function isValid(
		mixed $value,
	): bool
	{
		$valid = ($this->callback)($value);
		
		if($valid === false)
		{
			$error = new Error(self::ERROR_CALLBACK,
				sprintf($this->getMessage(self::ERROR_CALLBACK),
				$this->getElement()->getName()
			));
			$this->addError($error);
		}
		
		return $valid;
	}
}
