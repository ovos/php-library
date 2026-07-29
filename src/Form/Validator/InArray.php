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
 * InArray — the value is one of a known set (a <select>, a status column, an
 * enum a table stores).
 *
 * STRICT by default. A loose in_array() is the classic way a whitelist stops
 * being one: '0' == 'anything-non-numeric' under the old juggling rules, and
 * a status field would accept a word nobody listed.
 *
 * A LIST value (multi-select) is checked entry by entry, so one declaration
 * covers both shapes — that is the difference between "role" and "roles", and
 * writing the loop at every call site is how one of them ends up unchecked.
 *
 * An unset value passes (NotEmpty's job); an empty ARRAY is unset too, since
 * a multi-select with nothing chosen has nothing to check.
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
	
	/**
	 * How many allowed values the message names before it gives up and just
	 * says the field is wrong — a list of 200 country codes is not a message
	 */
	public const int MESSAGE_MAX_VALUES = 12;
	
	/**
	 * @param array $allowed the permitted values
	 */
	public function __construct(
		protected array $allowed,
		protected bool $strict = true,
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
		if($value === null || $value === '' || $value === [])
		{
			return true;
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
