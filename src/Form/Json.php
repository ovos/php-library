<?php
declare(strict_types=1);

namespace Ovos\Form;

use Ovos\Form;

use function array_key_exists;

/**
 * A Form fed by a decoded JSON body rather than an HTML submission.
 *
 * Same elements, same filters, same validators — one difference, and it is
 * the whole reason this class exists: a BROWSER posts every field of the form
 * it rendered, so "absent" and "empty" mean the same thing there. A JSON
 * client sends only what it wants to change, so the two are different
 * instructions and the form has to keep them apart:
 *
 *     {}                      leave retention_days alone
 *     {"retention_days":null} clear it — use the global default
 *
 * Two behaviours follow, and both live here rather than at every call site,
 * because getting either wrong is silent:
 *
 *   - wasSent() uses array_key_exists, not isset. Form::getRawValue() cannot
 *     answer this: it returns null for both cases.
 *   - a field that was NOT sent is not validated (isValid() below), because
 *     validating an absent key against NotEmpty fails a field the caller
 *     never mentioned. getInputValues() then hands back the DEFAULT for it,
 *     which is what setDefaults($stored) put there — so a partial body is a
 *     partial update by construction.
 *
 * Usage:
 *
 *     $form = new Form\Json;
 *     $form->retention_days
 *         ->addValidator(new Validator\Range(1, 3650));
 *     $form->setDefaults($project->toArray());  // what is stored now
 *     $form->setValues($body);                  // what the caller sent
 *
 *     if($form->isValid() === false)
 *     {
 *         return $this->unprocessable($form->getErrorMessages());
 *     }
 *
 *     $project->fill($form->getSentValues());
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Json extends Form
{
	/**
	 * Whether the caller mentioned this field AT ALL.
	 *
	 * array_key_exists, not isset: an explicit null is a value, and the
	 * difference between "clear it" and "leave it" is exactly what a partial
	 * update turns on.
	 */
	public function wasSent(
		string $id,
	): bool
	{
		return array_key_exists($id, $this->getRawValues());
	}
	
	/**
	 * Treat these fields as sent even when they are not — for a CREATE, where
	 * "absent" cannot mean "keep the stored value" because there is nothing
	 * stored to keep.
	 *
	 * An absent id becomes an explicit null, so the element's own validators
	 * produce the proper field error (NotEmpty says "name cannot be empty",
	 * a non-nullable Range says what the bounds are) instead of the save
	 * dying on a NOT NULL column. The caller applies this only on create;
	 * the form cannot know a create from an update.
	 */
	public function requireSent(
		string ...$ids,
	): static
	{
		foreach($ids as $id)
		{
			if($this->wasSent($id) === false)
			{
				$this->setValue($id, null);
			}
		}
		
		return $this;
	}
	
	/**
	 * Only the fields the caller actually sent, filtered and validated.
	 *
	 * getInputValues() answers for EVERY declared element (defaults included),
	 * which is what you want when writing a whole record. This is what you
	 * want when writing a partial one and the storage layer treats "present"
	 * as "assign".
	 */
	public function getSentValues(): array
	{
		$values = [];
		
		foreach($this->elements as $element)
		{
			if($element instanceof Element === false)
			{
				continue; // a nested form is not a value
			}
			
			$id = $element->getId(withFormId: false);
			if($this->wasSent($id))
			{
				// the CAST value — the storage form. Casts run only here,
				// after validation saw the uncast value; the HTML accessors
				// (getValues/getInputValues) stay cast-free.
				$values[$id] = $element->getCastValue();
			}
		}
		
		return $values;
	}
	
	/**
	 * field => first message, the shape an API error body wants.
	 *
	 * First and not all of them: a field with three broken rules is still one
	 * broken field to the caller, and the UI draws one line under one input.
	 *
	 * @return array<string, string>
	 */
	public function getErrorMessages(): array
	{
		$messages = [];
		
		foreach($this->getErrors() as $error)
		{
			$element = $error->getElement();
			$id = $element === null ? '' : $element->getId(withFormId: false);
			
			if($id !== '' && array_key_exists($id, $messages) === false)
			{
				$messages[$id] = (string)$error->getMessage();
			}
		}
		
		return $messages;
	}
	
	/**
	 * A field that was NOT sent has nothing to validate.
	 *
	 * Element::isValid() validates getUserValue() — the value without the
	 * default fallback — which is right for HTML, where the browser posts
	 * every field and a missing one really is empty. Here an absent key means
	 * "do not touch this", and validating it as null fails a NotEmpty on a
	 * field the caller never mentioned: a PATCH of one field would be refused
	 * for the other twenty. A rule on a field the caller DID send is enforced
	 * normally, and on a create the caller sends everything anyway, so
	 * required still bites where it should.
	 *
	 * The skip lives HERE and not in an element subclass, deliberately: the
	 * form is the party that knows what was sent, and a form-level skip
	 * covers every element type the same way — a specialised element
	 * (Element\Options, a project's own subclass) attached to this form gets
	 * JSON semantics without having to know they exist.
	 */
	public function isValid(): bool
	{
		$isValid = true;
		
		foreach($this->elements as $element)
		{
			// a nested form manages its own children
			if($element instanceof Element === false)
			{
				if($element->isValid() === false)
				{
					$isValid = false;
				}
				
				continue;
			}
			
			if($this->wasSent($element->getId(withFormId: false)) === false)
			{
				// wipe what a previous run left, or a second isValid() on the
				// same form would report errors this one did not produce
				$element->clearErrors();
				
				continue;
			}
			
			if($element->isValid() === false)
			{
				$isValid = false;
			}
		}
		
		return $isValid;
	}
}
