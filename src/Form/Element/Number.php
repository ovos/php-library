<?php
declare(strict_types=1);

namespace Ovos\Form\Element;

use Ovos\Form\Element;
use Ovos\Form\Filter;
use Ovos\Form\Validator;

/** Number element. */
class Number extends Element
{
	public const string ERROR_TYPE = Validator\Number::ERROR_NUMBER;
	
	public function __construct(
		?string $typeMessage = null,
	)
	{
		$validator = new Validator\Number;
		if($typeMessage !== null)
		{
			$validator->withMessage($typeMessage);
		}
		$this->addInputValidator($validator);
		$this->addCast(new Filter\Number);
	}
}
