<?php
declare(strict_types=1);

namespace Ovos\Form\Validator;

use Ovos\Form\Error;
use Ovos\Form\Validator;

use function is_numeric;
use function sprintf;

/** Accepts numeric values; null and an empty string are treated as unset. */
class Number extends Validator
{
	// Errors
	public const string ERROR_NUMBER = 'type_number';
	
	/**
	 * @var string[]
	 */
	protected array $messages =
	[
		self::ERROR_NUMBER => '"%s" must be a number.',
	];
	
	public function conforms(
		mixed $value,
	): bool
	{
		return $value === null || $value === '' || is_numeric($value);
	}
	
	public function isValid(
		mixed $value,
	): bool
	{
		if($this->conforms($value))
		{
			return true;
		}
		
		$this->addError(new Error(self::ERROR_NUMBER,
			sprintf($this->getMessage(self::ERROR_NUMBER),
			$this->getElement()->getName()
		)));
		
		return false;
	}
}
