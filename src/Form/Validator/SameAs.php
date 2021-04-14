<?php
declare(strict_types=1);

namespace Ovos\Form\Validator;

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
	/**#@+
	 * Error constants
	 */
	public const ERROR_DIFFERENT = 'different';
	/**#@-*/
	
	/**
	 * @var string[]
	 */
	protected array $_messages =
	[
		self::ERROR_DIFFERENT => '"%s" should be the same as "%s".',
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
	 * @param null|mixed $value
	 *
	 * @return bool
	 */
	public function isValid(mixed $value): bool
	{
		$element = $this->getElement()->getForm()->getElement($this->_id);
		$valid = $element->getValue() === $value;
		if($valid === false)
		{
			$error = new Error(self::ERROR_DIFFERENT, sprintf(
				$this->getMessage(self::ERROR_DIFFERENT), 
				$element->getLabel(),
				$this->getElement()->getLabel()
			));
			$this->addError($error);
		}
	
		return $valid;
	}
}
