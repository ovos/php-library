<?php
declare(strict_types=1);

namespace Ovos\View\Helper;

use Ovos\View\Helper\Elements;

/**
 * Body
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
class Body extends Elements
{
	/**
	 * @return self
	 */
	public function body(): Elements
	{
		return $this->elements()->body;
	}
}
