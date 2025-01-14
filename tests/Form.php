<?php
declare(strict_types=1);

namespace Tests;

use Ovos\Test;
use Ovos\Form as BaseForm;
use Ovos\Form\Filter;
use Ovos\Form\Validator;

/**
 * Form
 *
 * @package Tests
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
}
