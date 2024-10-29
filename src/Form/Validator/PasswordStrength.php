<?php
declare(strict_types=1);

namespace Ovos\Form\Validator;

use Ovos\Form\Error;
use Ovos\Form\Validator;
use Ovos\Strings;

/**
 * PasswordStrength
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
class PasswordStrength extends Validator
{
	/**#@+
	 * Error constants
	 */
	public const ERROR_WEAK = 'password_weak';
	/**#@-*/
	
	/**
	 * @var string[]
	 */
	protected array $_messages =
	[
		self::ERROR_WEAK => 'Password is not strong enough.',
	];
	
	/**
	 * @var int
	 */
	protected int $_length;
	
	/**
	 * @var bool
	 */
	protected bool $_uppercase;	
	
	/**
	 * @var bool
	 */
	protected bool $_digits;
	
	/**
	 * @var bool
	 */
	protected bool $_special;
	
	/**
	 * @param int $length
	 * @param bool $uppercase
	 * @param bool $digits
	 * @param bool $special
	 */
	public function __construct(int $length = 8,
		bool $uppercase = true,
		bool $digits = true,
		bool $special = true,
	)
	{
		$this->_length = $length;
		$this->_uppercase = $uppercase;
		$this->_digits = $digits;
		$this->_special = $special;
	}
	
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
		
		$valid = true;
		
		if(strlen($value) < $this->_length)
		{
			$valid = false;
		}
		
		// digits
		if($this->_digits
			&& preg_match("~[0-9]~", $value) === 0)
		{
			$valid = false;
		}
		
		// uppercase
		if($this->_uppercase
			&& preg_match("~[A-Z]~", $value) === 0)
		{
			$valid = false;
		}		
		
		// special
		if($this->_special
			&& preg_match("~[^\w]~", $value) === 0)
		{
			$valid = false;
		}
		
		if($valid === false)
		{
			$error = new Error(self::ERROR_WEAK, sprintf($this->getMessage(self::ERROR_WEAK),
				Strings::escapeForHtml($value)
			));
			$this->addError($error);
		}
		
		return $valid;
	}
}
