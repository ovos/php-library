<?php
declare(strict_types=1);

namespace Ovos\Form\Validator;

use Ovos\Form\Error;
use Ovos\Form\Validator;

use function is_numeric;
use function sprintf;

/**
 * Number — the value is numeric: "12abc" is a field error, never a silent
 * (int) cast to 12 somewhere downstream. The type gate behind
 * Element::asNumber(), and usable standalone. NULL and '' pass as "unset"
 * (the same notion Validator\Range uses) — pair with Range(nullable: false)
 * for a NOT NULL column.
 *
 * @author Marcin Gil <mg@ovos.at>
 */
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
	
	public function isValid(
		mixed $value,
	): bool
	{
		if($value === null || $value === '' || is_numeric($value))
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
