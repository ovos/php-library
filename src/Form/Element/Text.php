<?php
declare(strict_types=1);

namespace Ovos\Form\Element;

use Ovos\Form\Element;

/**
 * An element whose value is a piece of text — the class spelling of
 * Element::asText(), for setElement(). See asText() for the contract:
 * non-scalar values are a single field error, null passes the gate, and
 * the storage form is the string.
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Text extends Element
{
	public const string ERROR_TYPE = 'type_text';
	
	public function __construct(
		?string $typeMessage = null,
	)
	{
		$this->asText($typeMessage);
	}
}
