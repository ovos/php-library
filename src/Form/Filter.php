<?php
declare(strict_types=1);

namespace Ovos\Form;

/**
 * Filter
 *
 * @author Marcin Gil <mg@ovos.at>
 */
abstract class Filter
{
	abstract public function filter(
		mixed $value,
	): mixed;
}
