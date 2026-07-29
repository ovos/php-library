<?php
declare(strict_types=1);

namespace Tests;

use Ovos\Test;
use Ovos\Form as BaseForm;
use Ovos\Form\Filter;
use Ovos\Form\Validator;

use function count;
use function property_exists;

/**
 * Form
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Form extends Test
{
	public function subformId(): bool
	{
		$parentForm = new BaseForm('parent');
		$form = new BaseForm('sub');
		$form->setForm($parentForm);
		
		return $form->getId() === 'parent_sub';
	}
	
	public function isValid(): bool
	{
		$form = new BaseForm;
		$form->username
			->setLabel('E-mail')
			->addValidator(new Validator\NotEmpty)
			->addValidator(new Validator\EmailAddress);
		$form->username->setValue('test@test.com');
		
		$form->password
			->setLabel('Password')
			->addValidator(new Validator\NotEmpty)
			->addValidator(new Validator\PasswordStrength(
				length: 8,
				uppercase: true,
				digits: true,
				special: false
			));
		$form->password->setValue('Password0!');
		
		$form->password_confirm
			->setLabel('Confirm password')
			->addValidator(new Validator\NotEmpty)
			->addValidator(new Validator\SameAs('password'));
		$form->password_confirm->setValue($form->password->getValue());
		
		$form->acceptance
			->addValidator((new Validator\NotEmpty)
				->setMessage(Validator\NotEmpty::ERROR_EMPTY,
					'Acceptance is required.'
				)
			);
		$form->acceptance->setValue(1);
		
		$form->not_human
			->addFilter(new Filter\Checked)
			->addValidator(new Validator\Callback(function(mixed $value)
			{
				return $value === 0; // valid if unchecked
			}));
		$form->not_human->setValue(0);
		
		return $form->isValid();
	}
	
	/**
	 * isValid() has to VALIDATE every element, not stop at the first bad one.
	 *
	 * It was written as `$isValid = $isValid && $element->isValid()`, and &&
	 * short circuits: once one field failed, no element after it was validated
	 * at all. The form reported the first broken field and nothing else, so a
	 * user fixed it, submitted, and met the next one — one round trip per
	 * mistake — and an API answering with getErrors() listed one of five.
	 */
	public function everyElementIsValidatedNotJustUpToTheFirstFailure(): bool
	{
		$form = new BaseForm;
		
		foreach(['first', 'second', 'third'] as $field)
		{
			$form->$field
				->setLabel($field)
				->addValidator(new Validator\NotEmpty);
			$form->$field->setValue('');
		}
		
		$valid = $form->isValid();
		
		return $valid === false
			&& count($form->getErrors()) === 3
			// and each element knows about its own
			&& count($form->first->getErrors()) === 1
			&& count($form->second->getErrors()) === 1
			&& count($form->third->getErrors()) === 1;
	}
	
	/**
	 * A form is named values, filters and validators — none of which needs the
	 * application. The constructor used to call app() and store the result in
	 * a property nothing ever read, which made a form unconstructible outside
	 * a booted app for no benefit at all.
	 *
	 * Proven by construction here (this suite runs inside an app, so the call
	 * would have succeeded either way); what the test really pins is that no
	 * $app property is left for anything to depend on again.
	 */
	public function aFormNeedsNothingFromTheApplication(): bool
	{
		$form = new BaseForm;
		$form->field->addValidator(new Validator\NotEmpty);
		$form->field->setValue('present');
		
		return $form->isValid() === true
			&& property_exists($form, 'app') === false;
	}
	
	/** a later element still passes when an earlier one failed */
	public function aFailureDoesNotMarkLaterElementsInvalid(): bool
	{
		$form = new BaseForm;
		
		$form->broken->setLabel('broken')->addValidator(new Validator\NotEmpty);
		$form->broken->setValue('');
		
		$form->fine->setLabel('fine')->addValidator(new Validator\NotEmpty);
		$form->fine->setValue('filled in');
		
		return $form->isValid() === false
			&& count($form->fine->getErrors()) === 0
			&& count($form->getErrors()) === 1;
	}
}
