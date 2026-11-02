<?php
declare(strict_types=1);

namespace Tests\Form;

use Ovos\Form\Filter;
use Ovos\Form\Json;
use Ovos\Form\Validator;
use Ovos\Test;

class Messages extends Test
{
	public function theInputValidatorMessageDefaultsToEnglish(): bool
	{
		$form = new Json;
		$form->period->addInputValidator(new Validator\Number)
			->addCast(new Filter\Number);
		$form->setValues(['period' => '12abc']);
		$form->isValid();
		
		return $form->getErrorMessages()['period'] === '"period" must be a number.';
	}
	
	public function theInputValidatorMessageIsOverwritable(): bool
	{
		$form = new Json;
		$validator = new Validator\Number;
		$form->period->addInputValidator($validator);
		$validator->withMessage('"%s" muss eine Zahl sein.');
		$form->setValues(['period' => '12abc']);
		$form->isValid();
		
		return $form->getErrorMessages()['period'] === '"period" muss eine Zahl sein.'
			&& $form->getErrors()[0]->getValidator() === $validator;
	}
	
	public function anInputErrorCarriesItsValidator(): bool
	{
		$form = new Json;
		$form->period->addInputValidator(new Validator\Number)
			->addCast(new Filter\Number);
		$form->setValues(['period' => '12abc']);
		$form->isValid();
		
		$errors = $form->getErrors();
		
		return $errors !== []
			&& $errors[0]->getCode() === Validator\Number::ERROR_NUMBER
			&& $errors[0]->getValidator() instanceof Validator\Number;
	}
	
	public function aNullResultReportsTheValidatorsMessage(): bool
	{
		$validator = new Validator\NotNull;
		$validator->setMessage(Validator\NotNull::ERROR_NULL, '"%s" ist ung?ltig.');
		
		$form = new Json;
		$form->slug
			->addFilter(new Filter\Callback(static fn(mixed $value): mixed => null), each: false)
			->addValidator($validator);
		$form->setValues(['slug' => 'x']);
		
		return $form->isValid() === false
			&& $form->getErrorMessages()['slug'] === '"slug" ist ung?ltig.'
			&& $form->getErrors()[0]->getValidator() === $validator;
	}
	
	public function aNotNullMessageCanBePreset(): bool
	{
		$form = new Json;
		$form->slug->addValidator(new Validator\NotNull('must be a slug'));
		$form->other->addValidator(new Validator\NotNull);
		$form->setValues(['slug' => null, 'other' => null]);
		$form->isValid();
		
		return $form->getErrorMessages() === [
			'slug' => 'must be a slug',
			'other' => '"other" is not valid.',
		];
	}
	
	public function aValidatorMessageIsOverwritable(): bool
	{
		$form = new Json;
		$form->name->addValidator((new Validator\NotEmpty)
			->setMessage(Validator\NotEmpty::ERROR_EMPTY,
				'"%s" darf nicht leer sein.'));
		$form->setValues(['name' => '']);
		$form->isValid();
		
		return $form->getErrorMessages()['name'] === '"name" darf nicht leer sein.';
	}
}
