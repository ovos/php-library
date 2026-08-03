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
