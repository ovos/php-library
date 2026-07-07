<?php
declare(strict_types=1);

namespace Tests\Form;

use Ovos\Form as BaseForm;
use Ovos\Form\Validator;
use Ovos\Test;

/**
 * Validators - every form submission in production runs through these;
 * the existing Form test only walks the happy path
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Validators extends Test
{
	/**
	 * An element wired into a form, with the validator attached and BOUND
	 * (Element::isValid() does the binding in production; these tests call
	 * the validators directly)
	 */
	protected function element(
		Validator $validator,
		string $name = 'field',
	): BaseForm\Element
	{
		$form = new BaseForm;
		$element = $form->$name;
		$element->setLabel($name)
			->addValidator($validator);
		$validator->setElement($element);
		
		return $element;
	}
	
	public function notEmptyRejectsEmptyValues(): bool
	{
		$validator = new Validator\NotEmpty;
		$this->element($validator);
		
		$empty = $validator->isValid('');
		$errors = $validator->getErrors();
		
		return $empty === false
			&& $errors[0]->getCode() === Validator\NotEmpty::ERROR_EMPTY
			&& $validator->isValid('x') === true
			// PHP empty() semantics - the string "0" counts as empty
			&& $validator->isValid('0') === false;
	}
	
	public function emailAllowsEmptyButRejectsInvalid(): bool
	{
		$validator = new Validator\EmailAddress;
		$this->element($validator);
		
		// empty passes by design - require presence with NotEmpty
		return $validator->isValid('') === true
			&& $validator->isValid('mg@ovos.at') === true
			&& $validator->isValid('not-an-email') === false
			&& $validator->getErrors()[0]->getCode()
				=== Validator\EmailAddress::ERROR_INVALID;
	}
	
	public function passwordStrengthChecksEveryRule(): bool
	{
		$validator = new Validator\PasswordStrength; // 8, upper, digits, special
		$this->element($validator);
		
		$noSpecial = new Validator\PasswordStrength(special: false);
		$this->element($noSpecial);
		
		return $validator->isValid('Passw0rd!') === true
			&& $validator->isValid('P0w!') === false      // too short
			&& $validator->isValid('Password!') === false // no digit
			&& $validator->isValid('password0!') === false // no uppercase
			&& $validator->isValid('Password0') === false  // no special char
			&& $noSpecial->isValid('Password0') === true   // rule disabled
			&& $validator->isValid('') === true; // empty passes - combine with NotEmpty
	}
	
	public function sameAsComparesAgainstTheOtherElement(): bool
	{
		$form = new BaseForm;
		$form->password->setLabel('Password');
		$form->confirm->setLabel('Confirm');
		
		$validator = new Validator\SameAs('password');
		$form->confirm->addValidator($validator);
		$validator->setElement($form->confirm);
		
		$form->password->setValue('secret-1');
		
		$same = $validator->isValid('secret-1');
		$different = $validator->isValid('secret-2');
		
		return $same === true
			&& $different === false
			&& $validator->getErrors()[0]->getCode()
				=== Validator\SameAs::ERROR_DIFFERENT;
	}
	
	public function notHumanHoneypotOnlyPassesUntouched(): bool
	{
		$validator = new Validator\NotHuman;
		$this->element($validator);
		
		return $validator->isValid('') === true // a human never fills it
			&& $validator->isValid('spam text') === false
			&& $validator->getErrors()[0]->getCode()
				=== Validator\NotHuman::ERROR_NOT_HUMAN;
	}
	
	public function callbackDecidesAndIsBoundToTheValidator(): bool
	{
		$boundToValidator = false;
		$validator = new Validator\Callback(
			function(mixed $value) use (&$boundToValidator): bool
			{
				// the closure is re-bound to the validator instance
				$boundToValidator = $this instanceof Validator\Callback;
				
				return $value === 42;
			});
		$this->element($validator);
		
		return $validator->isValid(42) === true
			&& $boundToValidator === true
			&& $validator->isValid(41) === false
			&& $validator->getErrors()[0]->getCode()
				=== Validator\Callback::ERROR_CALLBACK;
	}
	
	public function ifCheckedRequiresTheDependencyFirst(): bool
	{
		$form = new BaseForm;
		$form->terms->setLabel('Terms');
		$form->newsletter->setLabel('Newsletter');
		
		$validator = new Validator\IfChecked('terms');
		$form->newsletter->addValidator($validator);
		$validator->setElement($form->newsletter);
		
		// newsletter checked while terms is not - invalid
		$form->terms->setValue(0);
		$form->newsletter->setValue(1);
		$dependencyMissing = $validator->isValid($form->newsletter->getValue());
		
		// both checked - valid
		$form->terms->setValue(1);
		$bothChecked = $validator->isValid($form->newsletter->getValue());
		
		// newsletter unchecked - the dependency does not matter
		$form->terms->setValue(0);
		$form->newsletter->setValue(0);
		$unchecked = $validator->isValid($form->newsletter->getValue());
		
		return $dependencyMissing === false
			&& $bothChecked === true
			&& $unchecked === true;
	}
}
