<?php
declare(strict_types=1);

namespace Ovos\Form\Element;

use Ovos\Form\Element;

/**
 * An element whose value is a list — the class spelling of
 * Element::asCollection(), for setElement(). See asCollection() for the
 * contract: a scalar where an array belongs is a single field error, and
 * null passes the gate ("no restriction" is a value for several lists).
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Collection extends Element
{
	public const string ERROR_TYPE = 'type_collection';
	
	public function __construct(
		?string $typeMessage = null,
	)
	{
		$this->asCollection($typeMessage);
	}
}
