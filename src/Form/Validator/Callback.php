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
	
	/** @var string[] */
	protected array $messages = [
		self::ERROR_CALLBACK => '"%s" is not valid.',
	];
	
	protected Closure $callback;
	
	public function __construct(
		Closure $callback,
		?string $message = null,
	)
	{
		$this->setCallback($callback);
		
		if($message !== null)
		{
			$this->withMessage($message);
		}
	}
	
	public function setCallback(
		Closure $callback,
	): static
	{
		// Non-static callbacks can access the validator through $this.
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
