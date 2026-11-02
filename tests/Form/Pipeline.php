<?php
declare(strict_types=1);

namespace Tests\Form;

use Ovos\Form\Filter;
use Ovos\Form\Json;
use Ovos\Form\Validator;
use Ovos\Test;

use function array_values;
use function count;
use function is_array;

class Pipeline extends Test
{
	public function inputValidationAcceptsAnyValidator(): bool
	{
		$filtered = false;
		$validated = false;
		$rule = new Validator\Callback(static fn(mixed $value): bool => is_array($value), 'expected an array');
		$form = new Json;
		$form->items
			->addInputValidator($rule)
			->addFilter(new Filter\Callback(static function(mixed $value) use (&$filtered): array
			{
				$filtered = true;
				return array_values($value);
			}), each: false)
			->addValidator(new Validator\Callback(static function(mixed $value) use (&$validated): bool
			{
				$validated = true;
				return true;
			}));
		$form->setValues(['items' => 'invalid']);
		
		return $form->items->getValue() === null
			&& $form->isValid() === false
			&& $filtered === false
			&& $validated === false
			&& count($form->getErrors()) === 1
			&& $form->getErrors()[0]->getValidator() === $rule;
	}
	
	public function aNullFilterResultIsCachedAndMayBeValid(): bool
	{
		$calls = 0;
		$form = new Json;
		$form->optional->addFilter(new Filter\Callback(static function(mixed $value) use (&$calls): mixed
		{
			$calls++;
			return null;
		}));
		$form->setValues(['optional' => '']);
		
		return $form->optional->getValue() === null
			&& $form->isValid()
			&& $form->getPresentValues() === ['optional' => null]
			&& $calls === 1;
	}
	
	public function updatingValuesInvalidatesInputValidationAndFilters(): bool
	{
		$form = new Json;
		$form->name
			->addInputValidator(new Validator\Text)
			->addFilter(new Filter\Trim)
			->addValidator(new Validator\NotEmpty);
		$form->setValues(['name' => []]);
		if($form->isValid() || $form->name->getValue() !== null)
		{
			return false;
		}
		$form->setValues(['name' => ' Alice ']);
		if($form->isValid() === false || $form->name->getValue() !== 'Alice' || $form->getErrors() !== [])
		{
			return false;
		}
		$form->setValue('name', ' ');
		if($form->isValid() || count($form->getErrors()) !== 1)
		{
			return false;
		}
		$form->name->setValue(' Bob ');
		
		return $form->isValid() && $form->name->getValue() === 'Bob' && $form->getErrors() === [];
	}
	
	public function filtersCanProcessItemsAndThenTheWholeArray(): bool
	{
		$seen = null;
		$form = new Json;
		$form->items
			->addFilter(new Filter\Trim)
			->addFilter(new Filter\Callback(static function(mixed $value) use (&$seen): array
			{
				$seen = $value;
				return array_values($value);
			}), each: false);
		$form->setValues(['items' => ['first' => ' a ', 'second' => ' b ']]);
		
		return $form->getValues() === ['items' => ['a', 'b']]
			&& $seen === ['first' => 'a', 'second' => 'b'];
	}
	
	public function aFailedRuleCanStopSubsequentValidation(): bool
	{
		$called = false;
		$form = new Json;
		$form->period
			->addValidator(new Validator\Range(1, 10), breakOnFailure: true)
			->addValidator(new Validator\Callback(static function(mixed $value) use (&$called): bool
			{
				$called = true;
				return false;
			}));
		$form->setValues(['period' => 0.5]);
		
		return $form->isValid() === false && $called === false && count($form->getErrors()) === 1;
	}
	
	public function repeatedValidationDoesNotDuplicateErrors(): bool
	{
		$form = new Json;
		$form->period
			->addValidator(new Validator\Range(1, 10))
			->addValidator(new Validator\WholeNumber);
		$form->setValues(['period' => 0.5]);
		$form->isValid();
		$form->isValid();
		
		return count($form->getErrors()) === 2;
	}
	
	public function explicitMembershipChecksEmptyValues(): bool
	{
		$form = new Json;
		$form->choice->addValidator(new Validator\InArray(['', 'known'], nullable: false));
		foreach([null, [], 'unknown'] as $value)
		{
			$form->setValue('choice', $value);
			if($form->isValid())
			{
				return false;
			}
		}
		$form->setValue('choice', '');
		
		return $form->isValid();
	}
	
	public function notNullAllowsOtherEmptyValues(): bool
	{
		$form = new Json;
		$form->value->addValidator(new Validator\NotNull);
		foreach(['', 0, false, []] as $value)
		{
			$form->setValue('value', $value);
			if($form->isValid() === false)
			{
				return false;
			}
		}
		$form->setValue('value', null);
		
		return $form->isValid() === false;
	}
}
