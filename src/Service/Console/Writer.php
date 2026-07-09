<?php
declare(strict_types=1);

namespace Ovos\Service\Console;

use Ovos\Logger\Writer as WriterInterface;
use Throwable;

/**
 * Log writer that forwards throwables to the error console via the Sender.
 * Silent by contract: forwarding failures are swallowed so logging can never
 * recurse into itself (logging while logging) or slow the host application —
 * the file writer has already recorded the event.
 *
 * @author Marcin Gil <mg@ovos.at>
 */
final class Writer implements WriterInterface
{
	public function __construct(
		protected readonly Sender $sender,
	)
	{
	}
	
	public function write(
		Throwable $event,
		array $extra,
	): void
	{
		try
		{
			$this->sender->captureException($event, $extra);
		}
		catch(Throwable)
		{
			// silence is the contract — never log while logging
		}
	}
}
