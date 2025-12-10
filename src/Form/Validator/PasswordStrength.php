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
	// Errors
	public const string ERROR_WEAK = 'password_weak';
	
	/**
	 * @var string[]
	 */
	protected array $messages =
	[
		self::ERROR_WEAK => 'Password is not strong enough.',
	];
	
	protected int $length;
	
	protected bool $uppercase;
	
	protected bool $digits;
	
	protected bool $special;
	
	public function __construct(
		int $length = 8,
		bool $uppercase = true,
		bool $digits = true,
		bool $special = true,
	)
	{
		$this->length = $length;
		$this->uppercase = $uppercase;
		$this->digits = $digits;
		$this->special = $special;
	}
	
	public function isValid(
		mixed $value,
	): bool
	{
		if(empty($value))
		{
			return true;
		}
		
		$valid = true;
		
		if(strlen($value) < $this->length)
		{
			$valid = false;
		}
		
		// digits
		if($this->digits
			&& preg_match('~[0-9]~', $value) === 0)
		{
			$valid = false;
		}
		
		// uppercase
		if($this->uppercase
			&& preg_match('~[A-Z]~', $value) === 0)
		{
			$valid = false;
		}
		
		// special
		if($this->special
			&& preg_match('~[^\w]~', $value) === 0)
		{
			$valid = false;
		}
		
		if($valid === false)
		{
			$error = new Error(self::ERROR_WEAK,
			sprintf($this->getMessage(self::ERROR_WEAK),
				Strings::escapeForHtml($value)
			));
			$this->addError($error);
		}
		
		return $valid;
	}
}
