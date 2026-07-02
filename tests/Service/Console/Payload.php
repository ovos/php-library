<?php
declare(strict_types=1);

namespace Tests\Service\Console;

use ErrorException;
use LogicException;
use Ovos\Service\Console\Payload as ConsolePayload;
use Ovos\Test;
use RuntimeException;

/**
 * Payload
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Payload extends Test
{
	public function throwablePriorityIsError(): bool
	{
		return ConsolePayload::priorityFor(new RuntimeException('x')) === 3;
	}
	
	public function errorSeverityMapsToSyslog(): bool
	{
		return ConsolePayload::priorityFor(new ErrorException('x', 0, E_USER_ERROR)) === 2
			&& ConsolePayload::priorityFor(new ErrorException('x', 0, E_WARNING)) === 4
			&& ConsolePayload::priorityFor(new ErrorException('x', 0, E_NOTICE)) === 5
			&& ConsolePayload::priorityFor(new ErrorException('x', 0, E_DEPRECATED)) === 6;
	}
	
	public function eventsChainOutermostFirst(): bool
	{
		$inner = new LogicException('inner cause');
		$outer = new RuntimeException('outer message', 0, $inner);
		
		$events = ConsolePayload::events($outer);
		
		return count($events) === 2
			&& $events[0]['className'] === RuntimeException::class
			&& $events[0]['previous'] === false
			&& $events[1]['className'] === LogicException::class
			&& $events[1]['previous'] === true
			&& $events[1]['message'] === 'inner cause';
	}
	
	public function fromThrowableShape(): bool
	{
		$payload = ConsolePayload::fromThrowable(
			new RuntimeException('boom'), null, ['orderId' => 7]);
		
		return $payload['v'] === 1
			&& $payload['priority'] === 3
			&& $payload['message'] === 'boom'
			&& $payload['extra'] === ['orderId' => 7]
			&& isset($payload['events'][0]['backtrace'])
			&& $payload['timestamp'] !== '';
	}
	
	public function fromThrowableHonorsPriorityOverride(): bool
	{
		$payload = ConsolePayload::fromThrowable(new RuntimeException('boom'), 6);
		
		return $payload['priority'] === 6;
	}
	
	public function fromMessageShape(): bool
	{
		$payload = ConsolePayload::fromMessage('deploy done', 6, ['tag' => 'v2']);
		
		return $payload['v'] === 1
			&& $payload['priority'] === 6
			&& $payload['message'] === 'deploy done'
			&& $payload['events'] === []
			&& $payload['extra'] === ['tag' => 'v2'];
	}
}
