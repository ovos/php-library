<?php
declare(strict_types=1);

namespace Ovos\Form;

/**
 * Filter
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
abstract class Filter
{
	/**
	 * @param null|mixed $value
	 *
	 * @return mixed
	 */
	abstract public function filter(mixed $value): mixed;
}
