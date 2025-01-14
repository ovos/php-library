<?php
declare(strict_types=1);

namespace Ovos\Form\Filter;

use Ovos\Form\Filter;

use function is_string;
use function is_int;

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
