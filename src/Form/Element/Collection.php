<?php
declare(strict_types=1);

namespace Ovos\Form\Element;

use Ovos\Form\Element;
use Ovos\Form\Validator;

/** Collection element. */
class Collection extends Element
{
	public const string ERROR_TYPE = Validator\Collection::ERROR_COLLECTION;
	
	public function __construct(
		?string $typeMessage = null,
	)
	{
		$validator = new Validator\Collection;
		if($typeMessage !== null)
		{
			$validator->withMessage($typeMessage);
		}
		$this->addInputValidator($validator);
	}
}
