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
	
	/**
	 * Range — and the reason it does not use empty(): 0 and '0' ARE empty(),
	 * and they are exactly the values a `min: 1` exists to reject.
	 */
	public function rangeChecksBoundsAndKeepsZeroCheckable(): bool
	{
		$validator = new Validator\Range(1, 3650);
		$this->element($validator, 'retention');
		
		return $validator->isValid(1) === true
			&& $validator->isValid(3650) === true
			&& $validator->isValid(0) === false
			&& $validator->isValid('0') === false
			&& $validator->isValid(3651) === false
			&& $validator->isValid(-5) === false
			// unset is NotEmpty's business, not a range's
			&& $validator->isValid(null) === true
			&& $validator->isValid('') === true;
	}
	
	/** a range says the field IS a number, so junk is not silently a 0 */
	public function rangeRejectsWhatIsNotANumber(): bool
	{
		$validator = new Validator\Range(1, 10);
		$this->element($validator, 'count');
		
		return $validator->isValid('12abc') === false
			&& $validator->isValid('abc') === false
			&& $validator->isValid('7') === true
			&& $validator->isValid(7.5) === true;
	}
	
	public function rangeBoundsAreOptional(): bool
	{
		$min = new Validator\Range(min: 0);
		$this->element($min, 'floor');
		$max = new Validator\Range(max: 100);
		$this->element($max, 'ceiling');
		
		return $min->isValid(999999) === true
			&& $min->isValid(-1) === false
			&& $max->isValid(-999999) === true
			&& $max->isValid(101) === false;
	}
	
	/** characters, not bytes — the cap usually mirrors a utf8mb4 VARCHAR */
	public function lengthCountsCharactersNotBytes(): bool
	{
		$validator = new Validator\Length(max: 5);
		$this->element($validator, 'name');
		
		return $validator->isValid('Grüße') === true      // 5 chars, 7 bytes
			&& $validator->isValid('Übergröße') === false
			&& $validator->isValid('12345') === true
			&& $validator->isValid('123456') === false
			&& $validator->isValid(null) === true;
	}
	
	public function lengthHasAMinimumToo(): bool
	{
		$validator = new Validator\Length(min: 3, max: 8);
		$this->element($validator, 'slug');
		
		return $validator->isValid('ab') === false
			&& $validator->isValid('abc') === true
			&& $validator->isValid('abcdefgh') === true
			&& $validator->isValid('abcdefghi') === false;
	}
	
	/**
	 * InArray is STRICT by default — a loose in_array() is the classic way a
	 * whitelist stops being one
	 */
	public function inArrayIsStrict(): bool
	{
		$validator = new Validator\InArray(['admin', 'viewer']);
		$this->element($validator, 'role');
		
		return $validator->isValid('admin') === true
			&& $validator->isValid('root') === false
			&& $validator->isValid(0) === false
			&& $validator->isValid(null) === true;
	}
	
	/** one declaration covers "role" and "roles" */
	public function inArrayChecksEveryEntryOfAList(): bool
	{
		$validator = new Validator\InArray(['http', 'cli', 'js']);
		$this->element($validator, 'types');
		
		return $validator->isValid(['http', 'js']) === true
			&& $validator->isValid(['http', 'php']) === false
			&& $validator->isValid([]) === true;
	}
	
	/**
	 * The point of a Url validator over FILTER_VALIDATE_URL: that filter is
	 * perfectly happy with javascript: and data:, and a stored URL is one that
	 * will be followed or linked.
	 */
	public function urlRefusesDangerousSchemes(): bool
	{
		$validator = new Validator\Url;
		$this->element($validator, 'health url');
		
		return $validator->isValid('https://example.com/health') === true
			&& $validator->isValid('javascript:alert(1)') === false
			&& $validator->isValid('data:text/html,<script>') === false
			&& $validator->isValid('http://example.com') === false // https only
			&& $validator->isValid(null) === true;
	}
	
	public function urlSchemesAreConfigurable(): bool
	{
		$validator = new Validator\Url(['http', 'https']);
		$this->element($validator, 'link');
		
		return $validator->isValid('http://example.com') === true
			&& $validator->isValid('https://example.com') === true
			&& $validator->isValid('ftp://example.com') === false;
	}
	
	/** a host is required — FILTER_VALIDATE_URL passes several hostless shapes */
	public function urlNeedsAHost(): bool
	{
		$validator = new Validator\Url;
		$this->element($validator, 'link');
		
		return $validator->isValid('https://') === false
			&& $validator->isValid('https:///path') === false
			&& $validator->isValid('not a url') === false;
	}
}
