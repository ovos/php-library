<?php
declare(strict_types=1);

namespace Tests\Form;

use Ovos\Form;
use Ovos\Form\Validator;
use Ovos\Test;

class Each extends Test
{
	public function rejectsScalarInputAndInvalidMembers(): bool
	{
		$form = new Form;
		$form->emails->addValidator(new Validator\Each(new Validator\EmailAddress));
		foreach(['a@example.com', ['bad', 'a@example.com']] as $value)
		{
			$form->setValues(['emails' => $value]);
			if($form->isValid() || $form->getErrors() === [])
			{
				return false;
			}
		}
		$form->setValues(['emails' => ['a@example.com']]);
		return $form->isValid() && $form->getErrors() === [];
	}
}
