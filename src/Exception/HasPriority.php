<?php
declare(strict_types=1);

namespace Ovos\Exception;

/**
 * Marks a throwable that carries its own console/report priority
 * (syslog 0-7), overriding the type-based mapping in
 * Ovos\Service\Console\Payload::priorityFor().
 *
 * @author Marcin Gil <mg@ovos.at>
 */
interface HasPriority
{
	/**
	 * Syslog priority (0 emerg .. 7 debug), or null to fall back to the
	 * default mapping.
	 */
	public function getPriority(): ?int;
}
