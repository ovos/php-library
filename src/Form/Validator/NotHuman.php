<?php
declare(strict_types=1);

namespace Ovos\Form\Validator;

use Ovos\Form\Error;
use Ovos\Form\Validator;

/**
 * NotHuman
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
class NotHuman extends Validator
{
	/**#@+
	 * Errors
	 */
	public const string ERROR_NOT_HUMAN = 'not_human';
	/**#@-*/
	
	/**
	 * @var string[]
	 */
	protected array $_messages =
	[
		self::ERROR_NOT_HUMAN => '"%s" is not valid.',
	];
	
	/**
	 * @param null|mixed $value
	 *
	 * @return bool
	 */
	public function isValid(mixed $value): bool
	{
		$valid = $value === '';
		if($valid === false)
		{
			$error = new Error(self::ERROR_NOT_HUMAN, sprintf($this->getMessage(self::ERROR_NOT_HUMAN),
				$this->getElement()->getName()
			));
			$this->addError($error);
		}
		
		return $valid;
	}
}
