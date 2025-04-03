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
	/**#@+
	 * Error constants
	 */
	public const string ERROR_NOT_CHECKED = 'not_checked';
	/**#@-*/
	
	/**
	 * @var string[]
	 */
	protected array $_messages =
	[
		self::ERROR_NOT_CHECKED => '"{0}" has to be completed first.',
	];
	
	/**
	 * @var string
	 */
	protected string $_id;
	
	/**
	 * @param string $id
	 */
	public function __construct(string $id)
	{
		$this->setId($id);
	}
	
	/**
	 * @param string $id
	 * 
	 * @return self
	 */
	public function setId(string $id): self
	{
		$this->_id = $id;
		
		return $this;
	}
	
	/**
	 * @return string
	 */
	public function getId(): string
	{
		return $this->_id;
	}
	
	/**
	 * @return Element
	 */
	public function getComparedElement(): Element
	{
		return $this->getElement()->getForm()->getElement($this->_id);
	}
	
	/**
	 * @param null|mixed $value
	 *
	 * @return bool
	 */
	public function isValid(mixed $value): bool
	{
		$elementComparedChecked = $this->getComparedElement()->getValue() === 1;
		$elementChecked = $this->getElement()->getValue() === 1;
		
		$valid = $elementChecked === false
			|| ($elementChecked === true && $elementComparedChecked === true);
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
