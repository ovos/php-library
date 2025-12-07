<?php
declare(strict_types=1);

namespace Ovos\Form\Validator;

use Ovos\Form\Element;
use Ovos\Form\Error;
use Ovos\Form\Validator;

/**
 * SameAs
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
class SameAs extends Validator
{
	// Errors
	public const string ERROR_DIFFERENT = 'different';
	
	/**
	 * @var string[]
	 */
	protected array $messages =
	[
		self::ERROR_DIFFERENT => '"%s" should be the same as "%s".',
	];
	
	protected string $id;
	
	public function __construct(
		string $id,
	)
	{
		$this->setId($id);
	}
	
	public function setId(
		string $id,
	): static
	{
		$this->id = $id;
		
		return $this;
	}
	
	public function getId(): string
	{
		return $this->id;
	}
	
	public function getComparedElement(): Element
	{
		return $this->getElement()
			->getForm()
			->getElement($this->id);
	}
	
	public function isValid(
		mixed $value,
	): bool
	{
		$valid = $this->getComparedElement()->getValue() === $value;
		if($valid === false)
		{
			$error = new Error(self::ERROR_DIFFERENT, sprintf(
				$this->getMessage(self::ERROR_DIFFERENT),
				$this->getComparedElement()->getLabel(),
				$this->getElement()->getLabel()
			));
			$this->addError($error);
		}
		
		return $valid;
	}
}
