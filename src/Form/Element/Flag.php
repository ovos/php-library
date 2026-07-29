<?php
declare(strict_types=1);

namespace Ovos\Form\Element;

use Ovos\Form\Element;

/**
 * An on/off switch.
 *
 * No gate and no rules: anything truthy counts as on — a JSON true, an
 * HTML checkbox's "on", a 1 — and the storage form is 1/0, which is what
 * a TINYINT column wants. A sent null is off, so a NOT NULL column never
 * meets a NULL.
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Flag extends Element
{
	public function __construct()
	{
		$this->addCast(static fn(mixed $value): int => $value ? 1 : 0);
	}
}
