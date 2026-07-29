<?php
declare(strict_types=1);

namespace Tests\Form;

use Ovos\Form\Element;
use Ovos\Form\Filter;
use Ovos\Form\Json as Subject;
use Ovos\Form\Validator;
use Ovos\Test;

use function count;

/**
 * Form\Json — the one thing that separates a JSON body from an HTML submission:
 * a browser posts every field, a JSON client posts only what it is changing,
 * so ABSENT and EMPTY stop meaning the same thing.
 *
 * Every test here is about that distinction. Get it wrong in one direction and
 * a PATCH of one field is refused because the other twenty are "empty"; get it
 * wrong in the other and a partial save wipes everything it did not mention.
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Json extends Test
{
	/**
	 * A settings form of the shape this exists for: a required name, an
	 * optional bounded number, and a URL — with the stored record as defaults.
	 */
	protected function form(
		array $sent,
	): Subject
	{
		$form = new Subject;
		
		$form->name
			->setLabel('name')
			->addFilter(new Filter\Trim)
			->addValidator(new Validator\NotEmpty);
		
		$form->retention_days
			->setLabel('retention')
			->addValidator(new Validator\Range(1, 3650));
		
		$form->health_url
			->setLabel('health url')
			->addValidator(new Validator\Url);
		
		$form->setDefaults([
			'name' => 'stored-name',
			'retention_days' => 21,
			'health_url' => 'https://stored.example.com/health',
		]);
		$form->setValues($sent);
		
		return $form;
	}
	
	/** array_key_exists, not isset — an explicit null IS a value */
	public function wasSentTellsAbsentFromNull(): bool
	{
		$form = $this->form(['retention_days' => null]);
		
		return $form->wasSent('retention_days') === true
			&& $form->wasSent('name') === false;
	}
	
	/**
	 * The case an HTML-shaped form gets wrong: a body that mentions one field
	 * must not be refused because the others are missing.
	 */
	public function anAbsentRequiredFieldIsNotValidated(): bool
	{
		$form = $this->form(['retention_days' => 30]);
		
		return $form->isValid() === true
			&& $form->getErrorMessages() === [];
	}
	
	/** …but a required field the caller DID send is enforced */
	public function aSentRequiredFieldIsStillValidated(): bool
	{
		$form = $this->form(['name' => '']);
		$valid = $form->isValid();
		$errors = $form->getErrorMessages();
		
		return $valid === false
			&& isset($errors['name'])
			&& $errors['name'] !== '';
	}
	
	/** what is not sent keeps what is stored — the partial-update contract */
	public function absentFieldsFallBackToTheStoredValue(): bool
	{
		$values = $this->form(['retention_days' => 30])->getInputValues();
		
		return $values['retention_days'] === 30
			&& $values['name'] === 'stored-name'
			&& $values['health_url'] === 'https://stored.example.com/health';
	}
	
	/** and the caller can ask for only what was actually sent */
	public function getSentValuesIsOnlyWhatArrived(): bool
	{
		$form = $this->form(['retention_days' => 30, 'name' => ' spaced ']);
		
		return $form->getSentValues() === [
			'name' => 'spaced', // filters still run
			'retention_days' => 30,
		];
	}
	
	/** an explicit null is a real instruction, not an absence */
	public function anExplicitNullIsSentAndPassesAnOptionalRule(): bool
	{
		$form = $this->form(['retention_days' => null]);
		
		return $form->isValid() === true
			&& $form->wasSent('retention_days') === true
			&& $form->getSentValues() === ['retention_days' => null];
	}
	
	public function errorMessagesAreKeyedByField(): bool
	{
		$form = $this->form([
			'name' => '',
			'retention_days' => 99999,
			'health_url' => 'javascript:alert(1)',
		]);
		$form->isValid();
		$messages = $form->getErrorMessages();
		
		return count($messages) === 3
			&& isset($messages['name'], $messages['retention_days'], $messages['health_url']);
	}
	
	/** validating twice must not accumulate or invent errors */
	public function validatingTwiceIsStable(): bool
	{
		$form = $this->form(['name' => '']);
		
		$form->isValid();
		$first = $form->getErrorMessages();
		$form->isValid();
		
		return $form->getErrorMessages() === $first && count($first) === 1;
	}
	
	/**
	 * The skip is FORM-level, not element-level, so every element type gets
	 * JSON semantics — the plain Element and any specialised subclass a
	 * project attaches with setElement(). An element-local override would
	 * silently revert those to validating fields nobody sent.
	 */
	public function anyElementTypeGetsTheAbsentFieldSkip(): bool
	{
		$form = new Subject;
		$form->setElement('role', new Element);
		$form->role
			->setLabel('role')
			->addValidator(new Validator\NotEmpty);
		$form->setValues(['something_else' => 'x']);
		
		return $form->isValid() === true
			&& $form->getErrorMessages() === [];
	}
}
