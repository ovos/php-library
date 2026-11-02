<?php
declare(strict_types=1);

namespace Ovos\Form\Filter;

use Ovos\Form\Filter;

/**
 * Integer
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Integer extends Filter
{
	public function __construct(
		protected bool $nullable = false,
	)
	{
	}
	
	public function filter(
		mixed $value,
	): ?int
	{
		if($this->nullable && ($value === null || $value === ''))
		{
			return null;
		}
		
		return (int)$value;
	}
}
