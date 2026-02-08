<?php
declare(strict_types=1);

namespace Ovos\Form\Filter;

use Ovos\Form\Filter;

/**
 * NullIfEmpty
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class NullIfEmpty extends Filter
{
	public function filter(
		mixed $value,
	): mixed
	{
		return empty($value)
			? null
			: $value;
	}
}
