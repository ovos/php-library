<?php
declare(strict_types=1);

namespace Ovos\Form\Filter;

use Ovos\Form\Filter;

class Number extends Filter
{
	public function filter(
		mixed $value,
	): null|int|float
	{
		return $value === null || $value === '' ? null : $value + 0;
	}
}
