<?php
declare(strict_types=1);

namespace Ovos\Form\Validator;

use Ovos\Form\Error;
use Ovos\Form\Validator;
use Ovos\Strings;

use function filter_var;
use function sprintf;

/**
 * EmailAddress
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
class EmailAddress extends Validator
{
	/**#@+
	 * Errors
	 */
	public const string ERROR_INVALID = 'email_invalid';
	/**#@-*/

	/**
	 * @var string[]
	 */
	protected array $_messages =
	[
		self::ERROR_INVALID => '"%s" is not a valid e-mail address.',
	];

	/**
	 * @param null|mixed $value
	 *
	 * @return bool
	 */
	public function isValid(mixed $value): bool
	{
		if(empty($value))
		{
			return true;
		}
	
		$valid = filter_var($value, FILTER_VALIDATE_EMAIL) !== false;
		if($valid === false)
		{
			$error = new Error(self::ERROR_INVALID, sprintf($this->getMessage(self::ERROR_INVALID),
				Strings::escapeForHtml($value)
			));
			$this->addError($error);
		}
	
		return $valid;
	}
}
