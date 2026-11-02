<?php
declare(strict_types=1);

namespace Ovos\Form\Element;

use Ovos\Form\Element;
use Ovos\Form\Filter;
use Ovos\Form\Validator;

/** Text element. */
class Text extends Element
{
	public const string ERROR_TYPE = Validator\Text::ERROR_TEXT;
	
	public function __construct(
		?string $typeMessage = null,
	)
	{
		$validator = new Validator\Text;
		if($typeMessage !== null)
		{
			$validator->withMessage($typeMessage);
		}
		$this->addInputValidator($validator);
		$this->addCast(new Filter\Text);
	}
}
