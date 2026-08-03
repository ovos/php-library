<?php
declare(strict_types=1);

namespace Ovos\Logger\Traits;

use Ovos\Service\Events;
use Throwable;

use function Ovos\container;

/**
 * Best-effort Events logging (file + console) — the house pattern for
 * paths that must never throw: ingest alerts, AI calls, auth audit.
 *
 * A plain string logs as NOTICE; the named argument selects the severity —
 * logEvent('auth: refused for %s', $email, priority: Priority::WARNING).
 *
 * @author Marcin Gil <mg@ovos.at>
 */
trait LogsEvents
{
	protected function logEvent(
		Throwable|string $event,
		string|int ...$args,
	): void
	{
		$container = container();
		if($container->isRegistered(Events::SYMBOL) === false)
		{
			return;
		}
		
		$container
			->get(Events::SYMBOL)
			->log($event, ...$args);
	}
}
