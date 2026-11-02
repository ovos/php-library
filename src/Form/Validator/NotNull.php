<?php
declare(strict_types=1);

namespace Ovos\Form\Validator;

use Ovos\Form\Error;
use Ovos\Form\Validator;

use function sprintf;

/** Rejects null while allowing empty strings, zero and empty collections. */
class NotNull extends Validator
{
	public const string ERROR_NULL = 'null';
	
	protected array $messages = [
		self::ERROR_NULL => '"%s" is not valid.',
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
	
	public function isValid(
		mixed $value,
	): bool
	{
		if($value !== null)
		{
			return true;
		}
		
		$this->addError(new Error(self::ERROR_NULL,
			sprintf($this->getMessage(self::ERROR_NULL), $this->getElement()->getName())));
		
		return false;
	}
}
