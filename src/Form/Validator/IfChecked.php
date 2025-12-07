<?php
declare(strict_types=1);

namespace Ovos\Form\Validator;

use Ovos\Form\Element;
use Ovos\Form\Error;
use Ovos\Form\Validator;

/**
 * IfChecked
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
class IfChecked extends Validator
{
	// Errors
	public const string ERROR_NOT_CHECKED = 'not_checked';
	
	/**
	 * @var string[]
	 */
	protected array $messages =
	[
		self::ERROR_NOT_CHECKED => '"{0}" has to be completed first.',
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
		$elementComparedChecked = $this->getComparedElement()->getValue() === 1;
		$elementChecked = $this->getElement()->getValue() === 1;
		
		$valid = $elementChecked === false
			|| ($elementComparedChecked === true);
		if($valid === false)
		{
			$error = new Error(self::ERROR_NOT_CHECKED, sprintf(
				$this->getMessage(self::ERROR_NOT_CHECKED),
				$this->getComparedElement()->getLabel(),
				$this->getElement()->getLabel()
			));
			$this->addError($error);
		}
		
		return $valid;
	}
}
