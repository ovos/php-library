<?php
declare(strict_types=1);

namespace Tests\Http;

use Ovos\Test;
use Ovos\Http\Trace as HttpTrace;

use function preg_match;

/**
 * Trace — the request-scoped correlation id: W3C traceparent parsing
 * and the generated fallback
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Trace extends Test
{
	public function parsesTraceparent(): bool
	{
		return HttpTrace::fromTraceparent(
			'00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01')
			=== '4bf92f3577b34da6a3ce929d0e0e4736';
	}
	
	public function acceptsUppercaseAndPadding(): bool
	{
		return HttpTrace::fromTraceparent(
			'  00-4BF92F3577B34DA6A3CE929D0E0E4736-00F067AA0BA902B7-01  ')
			=== '4bf92f3577b34da6a3ce929d0e0e4736';
	}
	
	public function acceptsFutureVersionsWithExtraFields(): bool
	{
		return HttpTrace::fromTraceparent(
			'cc-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01-extra-fields')
			=== '4bf92f3577b34da6a3ce929d0e0e4736';
	}
	
	public function rejectsMalformedHeaders(): bool
	{
		return HttpTrace::fromTraceparent('') === null
			&& HttpTrace::fromTraceparent('not a header') === null
			&& HttpTrace::fromTraceparent('00-4bf92f3577b34da6-00f067aa0ba902b7-01') === null // short trace id
			&& HttpTrace::fromTraceparent('00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa-01') === null // short parent id
			&& HttpTrace::fromTraceparent('zz-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01') === null;
	}
	
	public function rejectsForbiddenValues(): bool
	{
		// version ff and all-zero ids are explicitly invalid per the spec
		return HttpTrace::fromTraceparent(
				'ff-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01') === null
			&& HttpTrace::fromTraceparent(
				'00-00000000000000000000000000000000-00f067aa0ba902b7-01') === null
			&& HttpTrace::fromTraceparent(
				'00-4bf92f3577b34da6a3ce929d0e0e4736-0000000000000000-01') === null;
	}
	
	public function usesInboundTraceparent(): bool
	{
		$previous = $_SERVER['HTTP_TRACEPARENT'] ?? null;
		$_SERVER['HTTP_TRACEPARENT'] = '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01';
		HttpTrace::reset();
		
		$id = HttpTrace::id();
		
		$this->restoreHeader($previous);
		
		return $id === '4bf92f3577b34da6a3ce929d0e0e4736';
	}
	
	public function generatesAndMemoizesWithoutHeader(): bool
	{
		$previous = $_SERVER['HTTP_TRACEPARENT'] ?? null;
		unset($_SERVER['HTTP_TRACEPARENT']);
		HttpTrace::reset();
		
		$first = HttpTrace::id();
		$second = HttpTrace::id();
		
		HttpTrace::reset();
		$fresh = HttpTrace::id();
		
		$this->restoreHeader($previous);
		
		return preg_match('~^[0-9a-f]{32}$~', $first) === 1
			&& $second === $first
			&& $fresh !== $first;
	}
	
	protected function restoreHeader(
		?string $previous,
	): void
	{
		HttpTrace::reset();
		
		if($previous === null)
		{
			unset($_SERVER['HTTP_TRACEPARENT']);
			
			return;
		}
		
		$_SERVER['HTTP_TRACEPARENT'] = $previous;
	}
}
