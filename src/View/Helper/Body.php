<?php
declare(strict_types=1);

namespace Ovos\View\Helper;

/**
 * Body
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Body extends Elements
{
	public function body(): static
	{
		return $this->elements()->body;
	}
}
