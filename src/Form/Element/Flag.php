<?php
declare(strict_types=1);

namespace Ovos\Form\Element;

use Ovos\Form\Element;

/**
 * An on/off switch — the class spelling of Element::asFlag(), for
 * setElement(). See asFlag() for the contract: anything truthy counts as
 * on, the storage form is 1/0, a sent null is off.
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Flag extends Element
{
	public function __construct()
	{
		$this->asFlag();
	}
}
