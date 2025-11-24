<?php
declare(strict_types=1);

namespace Ovos\View\Helper;

/**
 * Body
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
class Body extends Elements
{
	/**
	 * @return static
	 */
	public function body(): static
	{
		return $this->elements()->body;
	}
}
