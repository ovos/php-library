<?php
declare(strict_types=1);

namespace Ovos\Form;

/**
 * Element
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Error
{
	protected ?Element $element = null;
	
	protected string $code;
	
	protected string $message;
	
	protected Validator $validator;
	
	public function __construct(
		string $code,
		?string $message = null,
	)
	{
		$this->setCode($code);
		$this->setMessage($message);
	}
	
	public function setElement(
		?Element $element,
	): static
	{
		$this->element = $element;
		
		return $this;
	}
	
	public function hasElement(): bool
	{
		return $this->element !== null;
	}
	
	public function getElement(): ?Element
	{
		return $this->element;
	}
	
	public function setValidator(
		Validator $validator,
	): static
	{
		$this->validator = $validator;
		
		return $this;
	}
	
	public function getValidator(): Validator
	{
		return $this->validator;
	}
	
	public function setMessage(
		?string $message,
	): static
	{
		$this->message = $message;
		
		return $this;
	}
	
	public function getMessage(): ?string
	{
		return $this->message;
	}
	
	public function setCode(
		string $code,
	): static
	{
		$this->code = $code;
		
		return $this;
	}
	
	public function getCode(): string
	{
		return $this->code;
	}
	
	public function __debugInfo(): array
	{
		return [
			'message' => $this->getMessage(),
			'code' => $this->getCode(),
		];
	}
}
