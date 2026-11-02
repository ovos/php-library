<?php
declare(strict_types=1);

namespace Ovos\Form\Filter;

use Ovos\Form\Filter;

class Text extends Filter
{
	public function filter(
		mixed $value,
	): ?string
	{
		return $value === null ? null : (string)$value;
	}
}
