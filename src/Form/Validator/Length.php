<?php
declare(strict_types=1);

namespace Ovos\Form\Validator;

use Ovos\Form\Error;
use Ovos\Form\Validator;
use Ovos\Strings;

use function is_scalar;
use function mb_strlen;
use function sprintf;

/**
 * Checks text length in characters.
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Length extends Validator
{
	// Errors
	public const string ERROR_TOO_SHORT = 'length_too_short';
	
	public const string ERROR_TOO_LONG = 'length_too_long';
	
	public const string ERROR_NOT_A_STRING = 'length_not_a_string';
	
	/**
	 * @var string[]
	 */
	protected array $messages =
	[
		self::ERROR_TOO_SHORT => '"%s" must be at least %s characters.',
		self::ERROR_TOO_LONG => '"%s" may be at most %s characters.',
		self::ERROR_NOT_A_STRING => '"%s" must be text.',
	];
	
	public function __construct(
		protected ?int $min = null,
		protected ?int $max = null,
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
			return true;
		}
		
		if(is_scalar($value) === false)
		{
			$this->fail(self::ERROR_NOT_A_STRING);
			
			return false;
		}
		
		$length = mb_strlen((string)$value);
		
		if($this->min !== null && $length < $this->min)
		{
			$this->fail(self::ERROR_TOO_SHORT, (string)$this->min);
			
			return false;
		}
		
		if($this->max !== null && $length > $this->max)
		{
			$this->fail(self::ERROR_TOO_LONG, (string)$this->max);
			
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
