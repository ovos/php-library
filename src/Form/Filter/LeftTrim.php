<?php
declare(strict_types=1);

namespace Ovos\Form\Filter;

use Ovos\Form\Filter;

/**
 * LeftTrim
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
class LeftTrim extends Trim
{
	/**
	 * @param null|mixed $value
	 *
	 * @return mixed
	 */
	public function filter(mixed $value): mixed
	{
		return ltrim($value, $this->getCharacterMask());
	}
}
