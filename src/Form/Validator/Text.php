<?php
declare(strict_types=1);

namespace Ovos\Form\Validator;

use Ovos\Form\Error;
use Ovos\Form\Validator;

use function is_scalar;
use function sprintf;

/** Accepts null or a scalar value. */
class Text extends Validator
{
	// Errors
	public const string ERROR_TEXT = 'type_text';
	
	/**
	 * @var string[]
	 */
	protected array $messages =
	[
		self::ERROR_TEXT => '"%s" must be text.',
	];
	
	public function conforms(
		mixed $value,
	): bool
	{
		return $value === null || is_scalar($value);
	}
	
	public function isValid(
		mixed $value,
	): bool
	{
		if($this->conforms($value))
		{
			return true;
		}
		
		$this->addError(new Error(self::ERROR_TEXT,
			sprintf($this->getMessage(self::ERROR_TEXT),
			$this->getElement()->getName()
		)));
		
		return false;
	}
}
