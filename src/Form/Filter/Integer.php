<?php
declare(strict_types=1);

namespace Ovos\Form\Filter;

use Ovos\Form\Filter;

/**
 * Integer
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
class Integer extends Filter
{
	/**
	 * @param null|mixed $value
	 *
	 * @return mixed
	 */
	public function filter($value)
	{
		return (int)$value;
	}
}
