<?php
declare(strict_types=1);

namespace Ovos\Form\Validator;

use Ovos\Form\Validator;
use Ovos\Form\Error;

/** Applies a validator to each value of a collection. */
class Each extends Validator
{
	protected array $messages = [
		Collection::ERROR_COLLECTION => '"%s" must be a list.',
	];

	public function __construct(protected Validator $validator)
	{
	}

	public function isValid(mixed $value): bool
	{
		if(is_array($value) === false)
		{
			$this->addError(new Error(Collection::ERROR_COLLECTION,
				sprintf($this->getMessage(Collection::ERROR_COLLECTION), $this->getElement()->getName())));
			return false;
		}

		$valid = true;
		$this->validator->setElement($this->getElement());
		foreach($value as $item)
		{
			$this->validator->clearErrors();
			if($this->validator->isValid($item) === false)
			{
				$valid = false;
				foreach($this->validator->getErrors() as $error)
				{
					$this->addError($error);
				}
			}
		}

		return $valid;
	}
}
