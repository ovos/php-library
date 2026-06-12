<?php
declare(strict_types=1);

namespace Ovos\Service\Console;

use ErrorException;
use Throwable;

use function date;
use function get_class;

/**
 * Maps throwables onto the error console v1 payload
 * (see ovos/console docs/API.V1.md)
 *
 * @author Marcin Gil <mg@ovos.at>
 */
final class Payload
{
	/**
	 * Syslog priority (0-7) for a throwable; ErrorException severity
	 * is mapped onto the classic error levels
	 */
	public static function priorityFor(
		Throwable $event,
	): int
	{
		if($event instanceof ErrorException)
		{
			return match($event->getSeverity())
			{
				E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR,
				E_USER_ERROR, E_RECOVERABLE_ERROR => 2, // critical
				E_WARNING, E_CORE_WARNING, E_COMPILE_WARNING,
				E_USER_WARNING => 4, // warning
				E_NOTICE, E_USER_NOTICE => 5, // notice
				E_DEPRECATED, E_USER_DEPRECATED => 6, // info
				default => 3, // error
			};
		}
		
		return 3; // any other uncaught throwable = error
	}
	
	/**
	 * Exception chain as v1 events, outermost first
	 */
	public static function events(
		Throwable $event,
	): array
	{
		$events = [];
		$previous = false;
		
		do
		{
			$events[] = [
				'message' => $event->getMessage(),
				'className' => get_class($event),
				'file' => $event->getFile(),
				'line' => $event->getLine(),
				'backtrace' => $event->getTraceAsString(),
				'previous' => $previous,
			];
			
			$previous = true;
		}
		while(($event = $event->getPrevious()) !== null);
		
		return $events;
	}
	
	/**
	 * Complete v1 error object (context is added by the Sender)
	 */
	public static function fromThrowable(
		Throwable $event,
		?int $priority = null,
		array $extra = [],
	): array
	{
		return [
			'v' => 1,
			'priority' => $priority ?? self::priorityFor($event),
			'timestamp' => date('c'),
			'message' => $event->getMessage(),
			'events' => self::events($event),
			'extra' => $extra,
		];
	}
	
	public static function fromMessage(
		string $message,
		int $priority = 5,
		array $extra = [],
	): array
	{
		return [
			'v' => 1,
			'priority' => $priority,
			'timestamp' => date('c'),
			'message' => $message,
			'events' => [],
			'extra' => $extra,
		];
	}
}
