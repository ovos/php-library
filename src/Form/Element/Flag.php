<?php
declare(strict_types=1);

namespace Ovos\Form\Element;

use Ovos\Form\Element;
use Ovos\Form\Filter;

/** Flag element. */
class Flag extends Element
{
	public function __construct()
	{
		$this->addCast(new Filter\Flag);
	}
}
