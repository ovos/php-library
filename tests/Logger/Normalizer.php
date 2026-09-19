<?php
declare(strict_types=1);

namespace Tests\Logger;

use Ovos\Exception as OvosException;
use Ovos\Logger\Normalizer as LoggerNormalizer;
use Ovos\Exception\Priority;
use Ovos\Test;
use RuntimeException;

/**
 * Normalizer
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Normalizer extends Test
{
	public function plainStringIsANotice(): bool
	{
		[$throwable, $extra] = LoggerNormalizer::normalize(['deploy done']);
		
		return $throwable instanceof OvosException
			&& $throwable->getMessage() === 'deploy done'
			&& $throwable->getPriority() === Priority::NOTICE
			&& $extra === [];
	}
	
	public function sprintfArgumentsAreApplied(): bool
	{
		[$throwable] = LoggerNormalizer::normalize(['user %s at %s', 'jane', 'web']);
		
		return $throwable->getMessage() === 'user jane at web'
			&& $throwable->getPriority() === Priority::NOTICE;
	}
	
	public function namedPrioritySetsTheMessagePriority(): bool
	{
		// log('disk almost full', priority: Priority::WARNING) — the variadic
		// collects the named argument under its string key
		[$plain] = LoggerNormalizer::normalize(['disk almost full', 'priority' => Priority::WARNING]);
		[$formatted] = LoggerNormalizer::normalize(['mail to %s failed', 'x@y', 'priority' => Priority::ERROR]);
		
		return $plain->getMessage() === 'disk almost full'
			&& $plain->getPriority() === Priority::WARNING
			&& $formatted->getMessage() === 'mail to x@y failed'
			&& $formatted->getPriority() === Priority::ERROR;
	}
	
	public function integerSprintfArgumentsAreNotAPriority(): bool
	{
		// the reason the priority is a NAMED argument: a trailing positional
		// integer is indistinguishable from a %d value
		[$throwable] = LoggerNormalizer::normalize(['found %d errors', 3]);
		
		return $throwable->getMessage() === 'found 3 errors'
			&& $throwable->getPriority() === Priority::NOTICE;
	}
	
	public function nonIntegerPriorityIsIgnored(): bool
	{
		[$throwable] = LoggerNormalizer::normalize(['deploy done', 'priority' => 'high']);
		
		return $throwable->getMessage() === 'deploy done'
			&& $throwable->getPriority() === Priority::NOTICE;
	}
	
	public function throwablePassesThroughWithExtras(): bool
	{
		$event = new RuntimeException('boom');
		$bare = LoggerNormalizer::normalize([$event]);
		$withExtras = LoggerNormalizer::normalize([$event, ['orderId' => 7]]);
		
		return $bare === [$event, []]
			&& $withExtras === [$event, ['orderId' => 7]];
	}
	
	public function namedPriorityStampsAnOvosThrowable(): bool
	{
		$event = new OvosException('slow response');
		[$throwable] = LoggerNormalizer::normalize([$event, 'priority' => Priority::INFO]);
		
		return $throwable === $event
			&& $event->getPriority() === Priority::INFO;
	}
	
	public function foreignThrowablePassesUnstamped(): bool
	{
		// no HasPriority on a foreign throwable — it passes through as-is and
		// Payload::priorityFor keeps deciding from its type
		$event = new RuntimeException('boom');
		
		return LoggerNormalizer::normalize([$event, 'priority' => Priority::INFO]) === [$event, []];
	}
	
	/**
	 * RULE: a logged STRING reports the line that logged it. The Normalizer
	 * wraps the message in an Exception, and a PHP exception remembers where it
	 * was CONSTRUCTED — so without the call-site stamp every logged message in
	 * every project reported this class's own file and line. An error console
	 * groups by that pair, links to it and reads the source snippet from it.
	 */
	public function aLoggedStringPointsAtTheLineThatLoggedIt(): bool
	{
		$line = __LINE__ + 1;
		[$throwable] = LoggerNormalizer::normalize(['the vendor answered 500']);
		
		return $throwable->getFile() === __FILE__
			&& $throwable->getLine() === $line;
	}
	
	/**
	 * …and so does the defect the fallback reports: "non-throwable event
	 * logged" is a complaint ABOUT a call site, so it had better name it
	 */
	public function theNonThrowableFallbackNamesTheCallSite(): bool
	{
		$line = __LINE__ + 1;
		[$throwable] = LoggerNormalizer::normalize([['not', 'a', 'throwable']]);
		
		return $throwable->getMessage() === 'non-throwable event logged'
			&& $throwable->getFile() === __FILE__
			&& $throwable->getLine() === $line;
	}
	
	/**
	 * A throwable passed in keeps its OWN origin: it was raised somewhere real
	 * and the logger is not entitled to move it
	 */
	public function aRealThrowableKeepsItsOwnOrigin(): bool
	{
		$thrown = new RuntimeException('the disk is full');
		$file = $thrown->getFile();
		$line = $thrown->getLine();
		[$throwable] = LoggerNormalizer::normalize([$thrown]);
		
		return $throwable === $thrown
			&& $throwable->getFile() === $file
			&& $throwable->getLine() === $line;
	}
	
	public function nothingToLogIsNull(): bool
	{
		return LoggerNormalizer::normalize([]) === null
			&& LoggerNormalizer::normalize(['priority' => Priority::NOTICE]) === null;
	}
	
	public function nonThrowableFallbackStaysAnError(): bool
	{
		[$throwable] = LoggerNormalizer::normalize([['not', 'loggable']]);
		
		return $throwable instanceof OvosException
			&& $throwable->getMessage() === 'non-throwable event logged'
			&& $throwable->getPriority() === null;
	}
}
