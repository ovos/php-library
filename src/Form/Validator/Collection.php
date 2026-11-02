<?php
declare(strict_types=1);

namespace Ovos\Form\Validator;

use Ovos\Form\Error;
use Ovos\Form\Validator;

use function is_array;
use function sprintf;

/** Accepts null or an array. */
class Collection extends Validator
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
