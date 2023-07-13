<?php
declare(strict_types=1);

namespace Ovos\Form\Filter;

use Ovos\Form\Filter;

/**
 * Checked
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
class Checked extends Filter
{
	/**
	 * @param mixed $value
	 *
	 * @return mixed
	 */
	public function filter(mixed $value): int
	{
		if(is_string($value) && $value == 'on')
		{
			return 1;
		}
		
		return 0;
	}
}
