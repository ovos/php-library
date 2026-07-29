<?php
declare(strict_types=1);

namespace Tests\Form;

use Ovos\Form\Json;
use Ovos\Form\Normalizer;
use Ovos\Form\Validator;
use Ovos\Test;

/**
 * The message contract: every message is a plain English default that lives
 * on its RULE — the validators' $messages, the type gate (a validator too),
 * the normalizer's own — each behind the same public setMessage()/
 * withMessage() API. A project overwrites any of them from outside, per
 * form, with a different variation or a translation. No translator runs
 * inside the machinery.
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Messages extends Test
{
	/** untouched, the gate speaks its English default */
	public function theGateMessageDefaultsToEnglish(): bool
	{
		$form = new Json;
		$form->period->asNumber();
		$form->setValues(['period' => '12abc']);
		$form->isValid();
		
		return $form->getErrorMessages()['period'] === '"period" must be a number.';
	}
	
	/** the gate is a validator — its message overwrites like any other */
	public function theGateMessageIsOverwritable(): bool
	{
		$form = new Json;
		$form->period->asNumber();
		$form->period->getGate()->withMessage('"%s" muss eine Zahl sein.');
		$form->setValues(['period' => '12abc']);
		$form->isValid();
		
		return $form->getErrorMessages()['period'] === '"period" muss eine Zahl sein.'
			&& $form->period->getGate() instanceof Validator\Number;
	}
	
	/** …and the gate error carries its validator, like any other error */
	public function aGateErrorCarriesItsValidator(): bool
	{
		$form = new Json;
		$form->period->asNumber();
		$form->setValues(['period' => '12abc']);
		$form->isValid();
		
		$errors = $form->getErrors();
		
		return $errors !== []
			&& $errors[0]->getCode() === Validator\Number::ERROR_NUMBER
			&& $errors[0]->getValidator() instanceof Validator\Number;
	}
	
	/** a normalizer owns its message, behind the same API */
	public function theNormalizerMessageIsOverwritable(): bool
	{
		$normalizer = new Normalizer\Callback(static fn(mixed $value): mixed => null);
		$normalizer->setMessage(Normalizer::ERROR_NORMALIZER, '"%s" ist ungültig.');
		
		$form = new Json;
		$form->slug->addNormalizer($normalizer);
		$form->setValues(['slug' => 'x']);
		$form->isValid();
		
		return $form->getErrorMessages()['slug'] === '"slug" ist ungültig.';
	}
	
	/** the addNormalizer() message parameter presets it, defaults stay English */
	public function aNormalizerMessagePresetBeatsTheDefault(): bool
	{
		$form = new Json;
		$form->slug->addNormalizer(static fn(mixed $value): mixed => null,
			'must be a slug');
		$form->other->addNormalizer(static fn(mixed $value): mixed => null);
		$form->setValues(['slug' => 'x', 'other' => 'y']);
		$form->isValid();
		
		$messages = $form->getErrorMessages();
		
		return $messages['slug'] === 'must be a slug'
			&& $messages['other'] === '"other" is not valid.';
	}
	
	/** validator messages overwrite through their public setter, as always */
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
