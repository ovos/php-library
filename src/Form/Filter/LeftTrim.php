<?php
declare(strict_types=1);

namespace Ovos\Form\Filter;

use function ltrim;

/**
 * LeftTrim
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class LeftTrim extends Trim
{
	public function filter(
		mixed $value,
	): ?string
	{
		if($value === null)
		{
			return null;
		}
		
		return ltrim($value, $this->getCharacterMask());
	}
}
