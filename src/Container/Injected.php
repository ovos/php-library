<?php
declare(strict_types=1);

namespace Ovos\Container;

/**
 * Injected
 *
 * @author Marcin Gil <mg@ovos.at>
 */
interface Injected
{
	public function process(
		object $object,
	): mixed;
}
