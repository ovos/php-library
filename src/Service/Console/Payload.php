<?php
declare(strict_types=1);

namespace Ovos\Service\Console;

use Ovos\Exception\HasPriority;
use ErrorException;
use Throwable;

use function date;
use function get_class;

use const E_COMPILE_ERROR;
use const E_COMPILE_WARNING;
use const E_CORE_ERROR;
use const E_CORE_WARNING;
use const E_DEPRECATED;
use const E_ERROR;
use const E_NOTICE;
use const E_PARSE;
use const E_RECOVERABLE_ERROR;
use const E_USER_DEPRECATED;
use const E_USER_ERROR;
use const E_USER_NOTICE;
use const E_USER_WARNING;
use const E_WARNING;

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
		// a throwable may declare its own priority (e.g. a router 404 as info)
		if($event instanceof HasPriority
			&& ($priority = $event->getPriority()) !== null)
		{
			return $priority;
		}
		
		if($event instanceof ErrorException)
		{
			return match($event->getSeverity())
			{
				E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR,
				E_USER_ERROR, E_RECOVERABLE_ERROR => Priority::CRITICAL,
				E_WARNING, E_CORE_WARNING, E_COMPILE_WARNING,
				E_USER_WARNING => Priority::WARNING,
				E_NOTICE, E_USER_NOTICE => Priority::NOTICE,
				E_DEPRECATED, E_USER_DEPRECATED => Priority::INFO,
				default => Priority::ERROR,
			};
		}
		
		return Priority::ERROR; // any other uncaught throwable
	}
	
	/**
	 * Exception chain as v1 events, outermost first. With $withSource a
	 * few source lines around each throw location ride along under the
	 * "code" key (SourceContext returns null for unreadable/generated
	 * files, so the key is simply omitted then).
	 */
	public static function events(
		Throwable $event,
		bool $withSource = true,
	): array
	{
		$events = [];
		$previous = false;
		
		do
		{
			$entry = [
				'message' => $event->getMessage(),
				'className' => get_class($event),
				'file' => $event->getFile(),
				'line' => $event->getLine(),
				'backtrace' => $event->getTraceAsString(),
				'previous' => $previous,
			];
			
			if($withSource)
			{
				$code = SourceContext::read($event->getFile(), $event->getLine());
				if($code !== null)
				{
					$entry['code'] = $code;
				}
			}
			
			$events[] = $entry;
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
		bool $withSource = true,
	): array
	{
		return [
			'v' => 1,
			'priority' => $priority ?? self::priorityFor($event),
			'timestamp' => date('c'),
			'message' => $event->getMessage(),
			'events' => self::events($event, $withSource),
			'extra' => $extra,
		];
	}
	
	public static function fromMessage(
		string $message,
		int $priority = Priority::NOTICE,
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
