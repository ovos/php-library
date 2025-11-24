<?php
declare(strict_types=1);

namespace Ovos\Form;

/**
 * Element
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
class Error
{
	/**
	 * @var ?Element
	 */
	protected ?Element $_element = null;
	
	/**
	 * @var string
	 */
	protected string $_code;
	
	/**
	 * @var string
	 */
	protected string $_message;
	
	/**
	 * @var Validator
	 */
	protected Validator $_validator;
	
	/**
	 * @param string $code
	 * @param ?string $message
	 */
	public function __construct(string $code, ?string $message = null)
	{
		$this->setCode($code);
		$this->setMessage($message);
	}
	
	/**
	 * @param ?Element $element
	 *
	 * @return static
	 */
	public function setElement(?Element $element): static
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
	 * @return ?Element
	 */
	public function getElement(): ?Element
	{
		return $this->_element;
	}
	
	/**
	 * @param Validator $validator
	 * 
	 * @return static
	 */
	public function setValidator(Validator $validator): static
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
	 * @return static
	 */
	public function setMessage(?string $message): static
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
	 * @return static
	 */
	public function setCode(string $code): static
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
	 * @return array
	 */
	public function __debugInfo(): array
	{
		return [
			'message' => $this->getMessage(),
			'code' => $this->getCode(),
		];
	}
}
