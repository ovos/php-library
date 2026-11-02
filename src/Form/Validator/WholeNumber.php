<?php
declare(strict_types=1);

namespace Ovos\Form\Validator;

use Ovos\Form\Error;
use Ovos\Form\Validator;
use Ovos\Strings;

use function floor;
use function is_numeric;
use function sprintf;

/**
 * Checks for a number without a fractional part. Null and '' are allowed.
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class WholeNumber extends Validator
{
	// Errors
	public const string ERROR_NOT_WHOLE = 'not_whole';
	
	/**
	 * @var string[]
	 */
	protected array $messages =
	[
		self::ERROR_NOT_WHOLE => '"%s" must be a whole number.',
	];
	
	public function __construct(
		?string $message = null,
	)
	{
		if($message !== null)
		{
			$this->withMessage($message);
		}
	}
	
	/** Returns false for nonnumeric values. */
	public static function isWhole(
		mixed $value,
	): bool
	{
		if(is_numeric($value) === false)
		{
			return false;
		}
		
		$number = (float)$value;
		
		return $number === floor($number);
	}
	
	public function isValid(
		mixed $value,
	): bool
	{
		if($value === null || $value === '' || self::isWhole($value))
		{
			return true;
		}
		
		$this->addError(new Error(self::ERROR_NOT_WHOLE,
			sprintf($this->getMessage(self::ERROR_NOT_WHOLE),
				Strings::escapeForHtml($this->getElement()->getName()))));
				
		return false;
	}
}
