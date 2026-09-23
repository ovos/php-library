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
 * WholeNumber — a number with nothing after the decimal point: the step an
 * INT column needs and a plain Number does not check. 5.7 passes Number and
 * Range, and MySQL rounds it on the way into the column — a monitor saved as
 * "every 5.7 minutes" pings on a cadence nobody asked for, and nothing reports
 * the change.
 *
 * Float-typed is fine: a JSON 60.0 is a whole number written by a client
 * whose language has one numeric type. NULL and '' pass as "unset", the notion
 * Number and Range use, so a missing required field reports Range's message
 * once rather than this one's as well. The check behind Element::asInteger(),
 * and usable standalone; isWhole() is the predicate for a callback.
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
	
	/**
	 * A number with nothing after the point — non-numeric is NOT whole, so
	 * this is safe as a standalone predicate
	 */
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
