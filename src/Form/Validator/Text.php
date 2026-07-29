<?php
declare(strict_types=1);

namespace Ovos\Form\Validator;

use Ovos\Form\Error;
use Ovos\Form\Validator;

use function is_scalar;
use function sprintf;

/**
 * Text — the value is a piece of text: null or a scalar. The type gate
 * behind Element::asText(), and usable standalone wherever a string shape
 * must hold. NULL passes: whether the field may be absent or empty stays
 * the business of NotEmpty.
 *
 * @author Marcin Gil <mg@ovos.at>
 */
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
	
	public function isValid(
		mixed $value,
	): bool
	{
		if($value === null || is_scalar($value))
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
