<?php
declare(strict_types=1);

namespace Ovos\Form\Filter;

use Ovos\Form\Filter;

class Flag extends Filter
{
	public function filter(
		mixed $value,
	): int
	{
		return $value ? 1 : 0;
	}
}
