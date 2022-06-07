<?php
declare(strict_types=1);

namespace Ovos\Form\Filter;

use Ovos\Form\Filter;

/**
 * FloatingPoint
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
class FloatingPoint extends Filter
{
	/**
	 * @param mixed $value
	 *
	 * @return mixed
	 */
	public function filter(mixed $value): float
	{
		return (float)$value;
	}
}
