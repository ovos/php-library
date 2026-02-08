<?php
declare(strict_types=1);

namespace Ovos\Form;

use function count;
use function array_key_exists;

/**
 * Validator
 *
 * @author Marcin Gil <mg@ovos.at>
 */
abstract class Validator
{
	protected Element $element;
	
	protected array $errors = [];
	
	protected array $messages = [];
	
	public function setElement(
		Element $element,
	): static
	{
		$this->element = $element;
		
		return $this;
	}
	
	public function getElement(): Element
	{
		return $this->element;
	}
	
	public function hasErrors(): bool
	{
		return count($this->errors) > 0;
	}
	
	public function addError(
		Error $error,
	): static
	{
		$error->setValidator($this);
		
		$this->errors[] = $error;
		
		return $this;
	}
	
	/**
	 * @return Error[]
	 */
	public function getErrors(): array
	{
		return $this->errors;
	}
	
	public function clearErrors(): static
	{
		$this->errors = [];
		
		return $this;
	}
	
	public function getMessages(): array
	{
		return $this->messages;
	}
	
	public function setMessage(
		string $errorCode,
		string $value,
	): static
	{
		$this->messages[$errorCode] = $value;
		
		return $this;
	}
	
	public function getMessage(
		string $errorCode,
	): ?string
	{
		if(array_key_exists($errorCode, $this->messages) === false)
		{
			return null;
		}
		
		return $this->messages[$errorCode];
	}
	
	abstract public function isValid(
		mixed $value,
	): bool;
}
