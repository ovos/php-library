<?php
declare(strict_types=1);

namespace Ovos\Form;
use function count;
use function array_key_exists;

/**
 * Validator
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
abstract class Validator
{
	/**
	 * @var Element
	 */
	protected Element $_element;

	/**
	 * @var Error[]
	 */
	protected array $_errors = [];

	/**
	 * @var string[]
	 */
	protected array $_messages = [];

	/**
	 * @param Element $element
	 *
	 * @return $this
	 */
	public function setElement(Element $element): self
	{
		$this->_element = $element;

		return $this;
	}

	/**
	 * @return Element
	 */
	public function getElement(): Element
	{
		return $this->_element;
	}
	
	/**
	 * @return bool
	 */
	public function hasErrors(): bool
	{
		return count($this->_errors) > 0;
	}

	/**
	 * @param Error $error
	 * 
	 * @return $this
	 */
	public function addError(Error $error): self
	{
		$error->setValidator($this);
		
		$this->_errors[] = $error;
		
		return $this;
	}

	/**
	 * @return Error[]
	 */
	public function getErrors(): array
	{
		return $this->_errors;
	}

	/**
	 * @return array
	 */
	public function getMessages(): array
	{
		return $this->_messages;
	}

	/**
	 * @param string $errorCode
	 * @param string $value
	 * 
	 * @return $this
	 */
	public function setMessage(string $errorCode, string $value): self
	{
		$this->_messages[$errorCode] = $value;
		
		return $this;
	}

	/**
	 * @param string $errorCode
	 * 
	 * @return null|string
	 */
	public function getMessage(string $errorCode): ?string
	{
		if(array_key_exists($errorCode, $this->_messages) === false)
		{
			return null;
		}
		
		return $this->_messages[$errorCode];
	}
	
	/**
	 * @param null|mixed $value
	 *
	 * @return bool
	 */
	abstract public function isValid(mixed $value): bool;
}
