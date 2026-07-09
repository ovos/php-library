<?php
declare(strict_types=1);

namespace Ovos\Logger;

use Throwable;

/**
 * A log writer: one destination the Events log fan-out delivers to (a file,
 * the error console, …). The Events service normalizes each logged message to
 * a Throwable + extras (see Normalizer) before handing it to every registered
 * writer, so a writer never sees the raw variadic log() arguments.
 *
 * @author Marcin Gil <mg@ovos.at>
 */
interface Writer
{
	public function write(
		Throwable $event,
		array $extra,
	): void;
}
