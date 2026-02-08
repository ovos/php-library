<?php
declare(strict_types=1);

namespace Ovos\Container;

use Ovos\Container;

/**
 * Injector
 *
 * @author Marcin Gil <mg@ovos.at>
 */
abstract class Injector
{
	abstract public function inject(
		Container $container,
	): object;
}
