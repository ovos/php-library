<?php
declare(strict_types=1);

namespace Ovos\Form;

use Ovos\Form;

/**
 * A Form whose ABSENT fields mean "leave it alone".
 *
 * The presence API — hasValue(), requireValues(), getPresentValues(),
 * getErrorMessages() — lives on Ovos\Form: any form can be asked what its
 * fed data mentions. What cannot live there is this class's one rule,
 * because the two worlds disagree about what absence MEANS:
 *
 *   - a BROWSER posts every field of the form it rendered, so an absent
 *     field is itself an instruction — an unchecked required checkbox
 *     (acceptance of terms) must fail its NotEmpty. The base Form validates
 *     absent fields for exactly that reason.
 *   - DATA — a decoded JSON body, a database row — names only the fields it
 *     carries, so absent and empty are different instructions:
 *
 *         {}                      leave retention_days alone
 *         {"retention_days":null} clear it — use the global default
 *
 *     Validating the absent twenty fields of a one-field PATCH would refuse
 *     the request over fields the data never mentioned.
 *
 * So this class changes validation and nothing else: a field that is not
 * present is skipped. The skip lives at form level and not in an element
 * subclass, deliberately — the form is the party that knows what is
 * present, and a form-level skip covers every element type the same way; a
 * specialised element (Element\Options, a project's own subclass) attached
 * to this form gets these semantics without having to know they exist.
 *
 * Usage:
 *
 *     $form = new Form\Json;
 *     $form->retention_days
 *         ->addValidator(new Validator\Range(1, 3650));
 *     $form->setDefaults($project->toArray());  // what is stored now
 *     $form->setValues($body);                  // what arrived
 *
 *     if($form->isValid() === false)
 *     {
 *         return $this->unprocessable($form->getErrorMessages());
 *     }
 *
 *     $project->fill($form->getPresentValues());
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Json extends Form
{
	/**
	 * A field that is NOT present has nothing to validate.
	 *
	 * Element::isValid() validates getUserValue() — the value without the
	 * default fallback — which is right for HTML, where the browser posts
	 * every field and a missing one really is empty. Here an absent key means
	 * "do not touch this", and validating it as null fails a NotEmpty on a
	 * field the data never mentioned. A rule on a field that IS present is
	 * enforced normally, and on a create requireValues() puts the required
	 * fields on the table, so required still bites where it should.
	 */
	public function isValid(): bool
	{
		$isValid = true;
		
		foreach($this->elements as $element)
		{
			if($this->hasValue($element->getId(withFormId: false)) === false)
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
