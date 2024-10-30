<?php
declare(strict_types=1);

namespace Ovos\Form\Filter;

use function ltrim;

/**
 * LeftTrim
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
class LeftTrim extends Trim
{
	/**
	 * @param mixed $value
	 *
	 * @return ?string
	 */
	public function filter(mixed $value): ?string
	{
		if($value === null)
		{
			return null;
		}
		
		return ltrim($value, $this->getCharacterMask());
	}
}
