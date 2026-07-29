<?php
declare(strict_types=1);

namespace Ovos\Form\Element;

use Ovos\Form\Element;
use Ovos\Form\Validator;

/**
 * An element whose value is a piece of text — the class spelling of
 * Element::asText(), for setElement(). See asText() for the contract:
 * non-scalar values are a single field error (the Validator\Text gate),
 * null passes, and the storage form is the string.
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Text extends Element
{
	public const string ERROR_TYPE = Validator\Text::ERROR_TEXT;
	
	public function __construct(
		?string $typeMessage = null,
	)
	{
		$this->asText($typeMessage);
	}
}
