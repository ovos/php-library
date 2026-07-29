<?php
declare(strict_types=1);

namespace Ovos\Form\Element;

use Ovos\Form\Element;

/**
 * An element whose value is a number — the class spelling of
 * Element::asNumber(), for setElement(). See asNumber() for the contract:
 * "12abc" is a field error, null and '' pass the gate as "unset", and the
 * storage form is the actual int|float (null/'' become null).
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Number extends Element
{
	public const string ERROR_TYPE = 'type_number';
	
	public function __construct(
		?string $typeMessage = null,
	)
	{
		$this->asNumber($typeMessage);
	}
}
