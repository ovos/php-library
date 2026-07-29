<?php
declare(strict_types=1);

namespace Ovos\Form\Validator;

use Ovos\Form\Error;

use function is_array;
use function sprintf;

/**
 * Collection — the value is a list: a scalar where an array belongs is a
 * field error, before it could reach an array-shaped normalizer or a JSON
 * column. The type gate behind Element::asCollection(), and usable
 * standalone. NULL passes: for several list fields null is itself a value
 * ("no restriction"), and where it is not, the field's rules say so.
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Collection extends Gate
{
	// Errors
	public const string ERROR_COLLECTION = 'type_collection';
	
	/**
	 * @var string[]
	 */
	protected array $messages =
	[
		self::ERROR_COLLECTION => '"%s" must be a list.',
	];
	
	public function conforms(
		mixed $value,
	): bool
	{
		return $value === null || is_array($value);
	}
	
	public function isValid(
		mixed $value,
	): bool
	{
		if($this->conforms($value))
		{
			return true;
		}
		
		$this->addError(new Error(self::ERROR_COLLECTION,
			sprintf($this->getMessage(self::ERROR_COLLECTION),
			$this->getElement()->getName()
		)));
		
		return false;
	}
}
