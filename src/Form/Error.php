<?php
declare(strict_types=1);

namespace Ovos\Form;

use Ovos\Form;

/**
 * Element
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
class Error
{
	/**
	 * @var Element
	 */
	protected Element $_element;

	/**
	 * @var string
	 */
	protected $_code;
	
	/**
	 * @var string
	 */
	protected $_message;

	/**
	 * @var Validator
	 */
	protected $_validator;
	
	/**
	 * @param string $code
	 * @param string $message
	 */
	public function __construct(string $code, string $message = null)
	{
		$this->setCode($code);
		$this->setMessage($message);
	}

	/**
	 * @param Element $element
	 *
	 * @return self
	 */
	public function setElement(Element $element): self
	{
		$this->_element = $element;

		return $this;
	}

	/**
	 * @return bool
	 */
	public function hasElement(): bool
	{
		return $this->_element !== null;
	}

	/**
	 * @return Element
	 */
	public function getElement(): Element
	{
		return $this->_element;
	}
	
	/**
	 * @param Validator $validator
	 * 
	 * @return self
	 */
	public function setValidator(Validator $validator): self
	{
		$this->_validator = $validator;
		
		return $this;
	}

	/**
	 * @return Validator
	 */
	public function getValidator(): Validator
	{
		return $this->_validator;
	}	

	/**
	 * @param ?string $message
	 * 
	 * @return self
	 */
	public function setMessage(?string $message): self
	{
		$this->_message = $message;
		
		return $this;
	}

	/**
	 * @return ?string
	 */
	public function getMessage(): ?string
	{
		return $this->_message;
	}
	
	/**
	 * @param string $code
	 * 
	 * @return self
	 */
	public function setCode(string $code): self
	{
		$this->_code = $code;
		
		return $this;
	}

	/**
	 * @return string
	 */
	public function getCode(): string
	{
		return $this->_code;
	}

	/**
	 * @return null|array
	 */
	public function __debugInfo()
	{
		return [
			'message' => $this->getMessage(),
			'code' => $this->getCode(),
		];
	}
}
