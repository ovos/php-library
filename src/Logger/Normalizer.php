<?php
declare(strict_types=1);

namespace Ovos\Logger;

use Ovos\Exception;
use Ovos\Exception\Priority;
use Ovos\Service\Events;
use Ovos\Service\Logger;
use Throwable;

use function in_array;
use function debug_backtrace;
use function count;
use function is_int;
use function is_string;
use function sprintf;

/**
 * Normalizes the variadic Logger/Events log() arguments to a single
 * (Throwable, extras) pair every Writer can consume:
 *   - log('message')                    → Exception('message') at NOTICE, []
 *   - log('fmt %s', $arg, …)            → Exception(sprintf(...)) at NOTICE, []
 *   - log('msg', priority: Priority::WARNING)
 *                                       → the message form at the given priority
 *   - log($throwable)                   → $throwable, []
 *   - log($throwable, ['key' => 'val']) → $throwable, ['key' => 'val']
 *   - log($throwable, priority: Priority::INFO)
 *                                       → $throwable stamped via withPriority()
 *     when it is an Ovos\Exception; a foreign throwable keeps its type-based
 *     mapping (see Payload::priorityFor) — set the priority at the throw site
 *
 * The named argument survives log()'s variadic because PHP collects unknown
 * named arguments under their string key; it is plucked here before sprintf
 * sees the positional list. A trailing POSITIONAL priority could never work:
 * sprintf arguments are allowed to be integers themselves.
 *
 * A logged string is an operational note, not a failure — hence NOTICE, the
 * lowest severity the console sender ships under its default log_level gate.
 * Throwables carry their own severity (HasPriority or the type mapping).
 *
 * @author Marcin Gil <mg@ovos.at>
 */
final class Normalizer
{
	/**
	 * The logging plumbing between a caller and this class: Service\Logger::log()
	 * and the Events fan-out that may precede it.
	 */
	protected const array PLUMBING = [self::class, Logger::class, Events::class];
	
	/** deep enough for Events → Logger → here, with room for a forwarder */
	protected const int TRACE_DEPTH = 8;
	
	/**
	 * @return array{0: Throwable, 1: array}|null null when nothing was passed
	 */
	public static function normalize(
		array $event,
	): ?array
	{
		// the optional named priority — log(…, priority: Priority::WARNING)
		$priority = $event['priority'] ?? null;
		if(is_int($priority) === false)
		{
			$priority = null;
		}
		unset($event['priority']);
		
		$count = count($event);
		if($count === 0)
		{
			return null;
		}
		
		// string message, optionally with sprintf arguments
		if(is_string($event[0]))
		{
			$message = $count > 1 ? sprintf(...$event) : $event[0];
			
			return [
				self::at(new Exception($message))->withPriority($priority ?? Priority::NOTICE),
				[],
			];
		}
		
		// a throwable, optionally followed by an extras array
		$extra = $count > 1 ? (array)$event[1] : [];
		
		if($event[0] instanceof Throwable)
		{
			if($priority !== null && $event[0] instanceof Exception)
			{
				$event[0]->withPriority($priority);
			}
			
			return [$event[0], $extra];
		}
		
		// a defect at the call site, not an operational note — the null
		// priority falls through to the ERROR mapping on purpose
		return [self::at(new Exception('non-throwable event logged')), $extra];
	}
	
	/**
	 * A wrapped message reports the line somebody wrote $log->error(…) on, not
	 * the line inside this class that built the Exception — the console groups,
	 * links and reads source by that pair, and the wrapper's own file would be
	 * the same for every logged message in every project.
	 */
	protected static function at(
		Exception $exception,
	): Exception
	{
		$site = self::callSite();
		
		return $site === [] ? $exception : $exception->raisedAt($site[0], $site[1]);
	}
	
	/**
	 * The call site: a backtrace frame carries the file and line its function
	 * was called FROM, so the outermost plumbing frame is the caller's own line
	 *
	 * @return array{0: string, 1: int}|array{}
	 */
	protected static function callSite(): array
	{
		$site = [];
		foreach(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, self::TRACE_DEPTH) as $frame)
		{
			if(in_array((string)($frame['class'] ?? ''), self::PLUMBING, true) === false)
			{
				break;
			}
			
			$site = [(string)($frame['file'] ?? ''), (int)($frame['line'] ?? 0)];
		}
		
		return $site;
	}
}
