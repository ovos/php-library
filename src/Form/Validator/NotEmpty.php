<?php
declare(strict_types=1);

namespace Ovos\Form\Validator;

use Ovos\Form\Error;
use Ovos\Form\Validator;

/**
 * NotEmpty
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
class NotEmpty extends Validator
{
	/**#@+
	 * Errors
	 */
	public const string ERROR_EMPTY = 'empty';
	/**#@-*/
	
	/**
	 * @var string[]
	 */
	protected array $_messages =
	[
		self::ERROR_EMPTY => '"%s" cannot be empty.',
	];
	
	/**
	 * @param null|mixed $value
	 *
	 * @return bool
	 */
	public function isValid(mixed $value): bool
	{
		$valid = empty($value) === false;
		if($valid === false)
		{
			$error = new Error(self::ERROR_EMPTY, sprintf($this->getMessage(self::ERROR_EMPTY),
				$this->getElement()->getName()
			));
			$this->addError($error);
		}
		
		return $valid;
	}
}
