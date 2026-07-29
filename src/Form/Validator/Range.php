<?php
declare(strict_types=1);

namespace Ovos\Form\Validator;

use Ovos\Form\Error;
use Ovos\Form\Validator;
use Ovos\Strings;

use function is_numeric;
use function sprintf;

/**
 * Range — a number between bounds, either of which may be left open.
 *
 * An UNSET value passes, like every other validator here: "must be filled in"
 * is NotEmpty's job, and an optional field with a range is an ordinary thing
 * to want. Unset means null or '' specifically, NOT empty() — 0 and '0' are
 * empty() and are exactly the values a `min: 1` exists to reject.
 *
 * A value that is not a number at all fails: declaring a range says the field
 * IS a number, and silently passing "abc" would leave the caller casting it
 * to 0 afterwards.
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Range extends Validator
{
	// Errors
	public const string ERROR_NOT_NUMERIC = 'range_not_numeric';
	
	public const string ERROR_TOO_SMALL = 'range_too_small';
	
	public const string ERROR_TOO_LARGE = 'range_too_large';
	
	/**
	 * @var string[]
	 */
	protected array $messages =
	[
		self::ERROR_NOT_NUMERIC => '"%s" must be a number.',
		self::ERROR_TOO_SMALL => '"%s" must be %s or more.',
		self::ERROR_TOO_LARGE => '"%s" must be %s or less.',
	];
	
	/**
	 * @param bool $nullable whether an unset value (null or '') passes. True
	 *                       is the HTML-form meaning — optional field,
	 *                       requiredness is NotEmpty's job. A JSON caller can
	 *                       send an EXPLICIT null, and a field whose column
	 *                       cannot hold one needs nullable: false, or the null
	 *                       sails past the range straight into a database
	 *                       error.
	 */
	public function __construct(
		protected int|float|null $min = null,
		protected int|float|null $max = null,
		protected bool $nullable = true,
		?string $message = null,
	)
	{
		if($message !== null)
		{
			$this->withMessage($message);
		}
	}
	
	public function isValid(
		mixed $value,
	): bool
	{
		if($value === null || $value === '')
		{
			if($this->nullable)
			{
				return true;
			}
			
			$this->fail(self::ERROR_NOT_NUMERIC);
			
			return false;
		}
		
		if(is_numeric($value) === false)
		{
			$this->fail(self::ERROR_NOT_NUMERIC);
			
			return false;
		}
		
		$number = $value + 0;
		
		if($this->min !== null && $number < $this->min)
		{
			$this->fail(self::ERROR_TOO_SMALL, (string)$this->min);
			
			return false;
		}
		
		if($this->max !== null && $number > $this->max)
		{
			$this->fail(self::ERROR_TOO_LARGE, (string)$this->max);
			
			return false;
		}
		
		return true;
	}
	
	protected function fail(
		string $code,
		?string $bound = null,
	): void
	{
		$label = Strings::escapeForHtml($this->getElement()->getName());
		
		$this->addError(new Error($code, $bound === null
			? sprintf($this->getMessage($code), $label)
			: sprintf($this->getMessage($code), $label, $bound)));
	}
}
