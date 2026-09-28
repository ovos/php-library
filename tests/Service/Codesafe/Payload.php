<?php
declare(strict_types=1);

namespace Tests\Service\Codesafe;

use ErrorException;
use LogicException;
use Ovos\Exception as OvosException;
use Ovos\Service\Codesafe\Payload as CodesafePayload;
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
		return CodesafePayload::priorityFor(new RuntimeException('x')) === 3;
	}
	
	public function errorSeverityMapsToSyslog(): bool
	{
		return CodesafePayload::priorityFor(new ErrorException('x', 0, E_USER_ERROR)) === 2
			&& CodesafePayload::priorityFor(new ErrorException('x', 0, E_WARNING)) === 4
			&& CodesafePayload::priorityFor(new ErrorException('x', 0, E_NOTICE)) === 5
			&& CodesafePayload::priorityFor(new ErrorException('x', 0, E_DEPRECATED)) === 6;
	}
	
	public function declaredPriorityOverridesMapping(): bool
	{
		// an Ovos exception may carry its own priority (e.g. a router 404 as
		// info); a null priority defers to the default type-based mapping
		return CodesafePayload::priorityFor((new OvosException('x'))->withPriority(6)) === 6
			&& CodesafePayload::priorityFor(new OvosException('x')) === 3;
	}

	public function eventsChainOutermostFirst(): bool
	{
		$inner = new LogicException('inner cause');
		$outer = new RuntimeException('outer message', 0, $inner);
		
		$events = CodesafePayload::events($outer);
		
		return count($events) === 2
			&& $events[0]['className'] === RuntimeException::class
			&& $events[0]['previous'] === false
			&& $events[1]['className'] === LogicException::class
			&& $events[1]['previous'] === true
			&& $events[1]['message'] === 'inner cause';
	}
	
	public function fromThrowableShape(): bool
	{
		$payload = CodesafePayload::fromThrowable(
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
		$payload = CodesafePayload::fromThrowable(new RuntimeException('boom'), 6);
		
		return $payload['priority'] === 6;
	}
	
	public function fromMessageShape(): bool
	{
		$payload = CodesafePayload::fromMessage('deploy done', 6, ['tag' => 'v2']);
		
		return $payload['v'] === 1
			&& $payload['priority'] === 6
			&& $payload['message'] === 'deploy done'
			&& $payload['events'] === []
			&& $payload['extra'] === ['tag' => 'v2'];
	}
}
