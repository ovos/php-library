<?php
declare(strict_types=1);

namespace Ovos\Form\Validator;

use Ovos\Form\Error;
use Ovos\Form\Validator;
use Ovos\Strings;

use function count;
use function implode;
use function in_array;
use function is_array;
use function is_scalar;
use function sprintf;

/**
 * Checks membership using strict comparison by default. Array values are checked item by item.
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class InArray extends Validator
{
	// Errors
	public const string ERROR_NOT_ALLOWED = 'not_allowed';
	
	/**
	 * @var string[]
	 */
	protected array $messages =
	[
		self::ERROR_NOT_ALLOWED => '"%s" must be one of: %s.',
	];
	
	/** Maximum number of choices listed in an error message. */
	public const int MESSAGE_MAX_VALUES = 12;
	
	/**
	 * @param array $allowed the permitted values
	 * @param bool $nullable whether unset values may bypass the membership check
	 */
	public function __construct(
		protected array $allowed,
		protected bool $strict = true,
		?string $message = null,
		protected bool $nullable = true,
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
		if($this->nullable && ($value === null || $value === '' || $value === []))
		{
			return true;
		}
		
		if($value === [])
		{
			$this->fail();
			
			return false;
		}
		
		foreach(is_array($value) ? $value : [$value] as $item)
		{
			if(in_array($item, $this->allowed, $this->strict) === false)
			{
				$this->fail();
				
				return false;
			}
		}
		
		return true;
	}
	
	protected function fail(): void
	{
		$named = [];
		foreach($this->allowed as $allowed)
		{
			if(is_scalar($allowed))
			{
				$named[] = (string)$allowed;
			}
		}
		
		$list = count($named) > self::MESSAGE_MAX_VALUES || $named === []
			? '…'
			: implode(', ', $named);
		
		$this->addError(new Error(
			self::ERROR_NOT_ALLOWED,
			sprintf(
				$this->getMessage(self::ERROR_NOT_ALLOWED),
				Strings::escapeForHtml($this->getElement()->getName()),
				Strings::escapeForHtml($list),
			),
		));
	}
}
