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
	public function filter(
		mixed $value,
	): int
	{
		if($value === 'on')
		{
			return 1;
		}
		
		if($value === 1)
		{
			return 1;
		}
		
		return 0;
	}
}
