<?php
declare(strict_types=1);

namespace Ovos\Form;

use Ovos\Form;

/**
 * Validates only submitted fields. An explicit null is validated; an absent key is skipped.
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Json extends Form
{
	public function isValid(): bool
	{
		$isValid = true;
		
		foreach($this->elements as $element)
		{
			if($this->hasValue($element->getId(withFormId: false)) === false)
			{
				// Clear errors from a previous submission.
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
