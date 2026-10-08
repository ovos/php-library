<?php
declare(strict_types=1);

namespace Tests\Cache;

use Ovos\Cache\Policy as Subject;
use Ovos\Test;
use InvalidArgumentException;
use TypeError;

use function str_contains;

/**
 * Policy - the options get() takes after $tags (stale:, soft:, refresh:,
 * staleIfError:, queue:, queueLockTtlMs:), read from its variadic: named as
 * callers write them, positional in the documented order; anything else - an
 * unknown name, one position too many, a wrong type - throws, so a typo is
 * never silently ignored
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Policy extends Test
{
	public function namedOptionsAreRead(): bool
	{
		$policy = Subject::from(['stale' => 60, 'soft' => true, 'staleIfError' => 30]);
		
		return $policy->stale === 60
			&& $policy->soft === true
			&& $policy->staleIfError === 30
			&& $policy->refresh === false
			&& $policy->queue === null
			&& $policy->queueLockTtlMs === null;
	}
	
	public function positionalOptionsFollowTheDocumentedOrder(): bool
	{
		$policy = Subject::from([false, 2500, 60, true, true, 30]);
		
		return $policy->queue === false
			&& $policy->queueLockTtlMs === 2500
			&& $policy->stale === 60
			&& $policy->soft === true
			&& $policy->refresh === true
			&& $policy->staleIfError === 30;
	}
	
	public function noOptionsAreTheDefaults(): bool
	{
		return Subject::from([]) == new Subject();
	}
	
	public function anUnknownOptionThrows(): bool
	{
		try
		{
			Subject::from(['stal' => 60]);
			
			return false;
		}
		catch(InvalidArgumentException $exception)
		{
			return str_contains($exception->getMessage(), 'stal');
		}
	}
	
	public function onePositionTooManyThrows(): bool
	{
		try
		{
			Subject::from([null, null, 0, false, false, 0, 'one more']);
			
			return false;
		}
		catch(InvalidArgumentException)
		{
			return true;
		}
	}
	
	public function aWronglyTypedOptionThrows(): bool
	{
		try
		{
			Subject::from(['stale' => 'sixty']);
			
			return false;
		}
		catch(TypeError)
		{
			return true;
		}
	}
}
