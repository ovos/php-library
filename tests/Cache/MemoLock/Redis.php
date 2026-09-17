<?php
declare(strict_types=1);

namespace Tests\Cache\MemoLock;

use Ovos\ArrayObject;
use Ovos\Container\Inject;
use Ovos\Cache\MemoLock\Redis as RedisMemoLock;
use Ovos\Test;
use Ovos\Test\Internal;
use Ovos\Test\Cache\Store\TraitRedis;
use Override;

use function abs;
use function microtime;
use function sprintf;

/**
 * A lock whose request has 150 ms left, whatever max_execution_time says
 */
class DeadlineMemoLock extends RedisMemoLock
{
	#[Override]
	public static function deadline(
		?int $limitS = null,
		?float $startedAt = null,
	): ?float
	{
		return microtime(true) + 0.15;
	}
}

/**
 * Redis
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Redis extends Test
{
	use TraitRedis;
	
	public const string KEY_ITEM = 'item';
	
	#[Inject('config')]
	protected ArrayObject $config;
	
	protected ?RedisMemoLock $memoLock = null;
	
	public function __construct()
	{
		if($this->cacheConfig->getPath(['persistent', 'queue', 'enabled']) !== true)
		{
			$this->setDisabled(true,
				sprintf('"queue" is not enabled in cache config.')
			);
			
			return;
		}
		
		$this->memoLock = $this->getMemoLock();
	}
	
	public function disabledCallsResolver(): bool
	{
		$this->memoLock->setQueueEnabled(false);
		
		$called = 0;
		$result = $this->memoLock
			->lockAndQueue(self::KEY_ITEM,
				resolver: function() use (&$called)
				{
					$called++;
					return 'ok';
				},
			);
		
		return $called === 1 && $result === 'ok';
	}
	
	public function releaseActiveLockNoActive(): bool
	{
		return $this->memoLock
			->releaseActiveLock(self::KEY_ITEM) === false;
	}
	
	public function renewLockNoActive(): bool
	{
		return $this->memoLock
			->renewLock(self::KEY_ITEM) === false;
	}
	
	public function acquireAndReleaseLock(): bool
	{
		try
		{
			$this->memoLock
				->lockAndQueue(self::KEY_ITEM,
					resolver: fn() => 'ok',
					queue: true,
				);
			
			$lockKey = $this->memoLock
				->getPrefixer()
				->prefix(RedisMemoLock::TYPE_LOCK, self::KEY_ITEM);
			
			$existsBefore = $this->memoLock->getClient()
				->exists($lockKey) === 1;
			$released = $this->memoLock
				->releaseActiveLock(self::KEY_ITEM);
			$existsAfter = $this->memoLock->getClient()
				->exists($lockKey) === 0;
			
			return $existsBefore && $released && $existsAfter;
		}
		finally
		{
			$this->memoLock->getClient()
				->del(self::KEY_ITEM);
		}
	}
	
	public function waitForReleaseWithoutLock(): bool
	{
		return $this->memoLock
			->waitForRelease(self::KEY_ITEM) === true;
	}
	
	public function waitForReleaseOwnLock(): bool
	{
		try
		{
			$this->memoLock
				->lockAndQueue(self::KEY_ITEM,
					resolver: fn() => 'ok',
					queue: true,
				);
			
			// a lock held by this very request never blocks it
			return $this->memoLock
				->waitForRelease(self::KEY_ITEM) === true;
		}
		finally
		{
			$this->memoLock->releaseActiveLock(self::KEY_ITEM);
			$this->memoLock->getClient()->del(self::KEY_ITEM);
		}
	}
	
	public function waitForReleaseForeignLockExpires(): bool
	{
		$lockKey = $this->memoLock
			->getPrefixer()
			->prefix(RedisMemoLock::TYPE_LOCK, self::KEY_ITEM);
		$client = $this->memoLock->getClient();
		
		try
		{
			// a lock held by another process, gone after 300ms
			$client->set($lockKey, 'foreign', ['PX' => 300]);
			
			$start = microtime(true);
			$released = $this->memoLock
				->waitForRelease(self::KEY_ITEM, 300);
			$elapsed = microtime(true) - $start;
			
			return $released === true
				&& $elapsed >= 0.1;
		}
		finally
		{
			$client->del($lockKey);
		}
	}
	
	public function deadlineWithoutLimitIsNull(): bool
	{
		return RedisMemoLock::deadline(0) === null
			&& RedisMemoLock::deadline(-1) === null;
	}
	
	public function deadlineIsASecondBeforeTheLimit(): bool
	{
		$now = microtime(true);
		$until = RedisMemoLock::deadline(30, $now - 5.0);
		
		// started 5 s ago with 30 s to spend: 24 s left, not 25
		return $until !== null
			&& abs($until - ($now + 24.0)) < 0.05;
	}
	
	public function deadlineAlreadyPastIsNull(): bool
	{
		$now = microtime(true);
		
		// started 40 s ago with 30 s to spend: the timer did not kill us, so
		// it does not count the clock - nothing to cap
		return RedisMemoLock::deadline(30, $now - 40.0) === null
			&& RedisMemoLock::deadline(30, $now - 29.5) === null;
	}
	
	/**
	 * A waiter whose request is about to run out of time stops waiting and
	 * produces the value itself, well inside the lock's TTL and its attempts
	 */
	public function waiterStopsAtTheDeadline(): bool
	{
		$memoLock = $this->getMemoLock(DeadlineMemoLock::class);
		$lockKey = $memoLock
			->getPrefixer()
			->prefix(RedisMemoLock::TYPE_LOCK, self::KEY_ITEM);
		$client = $memoLock->getClient();
		
		try
		{
			// held by another process for longer than any wait here
			$client->set($lockKey, 'foreign', ['PX' => 5000]);
			
			$start = microtime(true);
			$result = $memoLock
				->lockAndQueue(self::KEY_ITEM,
					fetcher: fn() => null,
					resolver: fn() => 'mine',
					queue: true,
					queueLockTtlMs: 5000,
				);
			$elapsed = microtime(true) - $start;
			
			// the deadline is 150 ms out: one capped wait, then our own value
			return $result === 'mine'
				&& $elapsed < 1.0
				&& $client->get($lockKey) === 'foreign';
		}
		finally
		{
			$client->del($lockKey);
		}
	}
	
	/**
	 * The same for a caller that only waits for a release: at the deadline
	 * the lock counts as still held, and the caller decides
	 */
	public function waitForReleaseStopsAtTheDeadline(): bool
	{
		$memoLock = $this->getMemoLock(DeadlineMemoLock::class);
		$lockKey = $memoLock
			->getPrefixer()
			->prefix(RedisMemoLock::TYPE_LOCK, self::KEY_ITEM);
		$client = $memoLock->getClient();
		
		try
		{
			$client->set($lockKey, 'foreign', ['PX' => 5000]);
			
			$start = microtime(true);
			$released = $memoLock
				->waitForRelease(self::KEY_ITEM, 5000);
			$elapsed = microtime(true) - $start;
			
			return $released === false
				&& $elapsed < 1.0;
		}
		finally
		{
			$client->del($lockKey);
		}
	}
	
	public function waitForReleaseForeignLockHeld(): bool
	{
		$lockKey = $this->memoLock
			->getPrefixer()
			->prefix(RedisMemoLock::TYPE_LOCK, self::KEY_ITEM);
		$client = $this->memoLock->getClient();
		
		try
		{
			// a lock that outlives every wait attempt
			$client->set($lockKey, 'foreign', ['PX' => 30000]);
			
			return $this->memoLock
				->waitForRelease(self::KEY_ITEM, 100) === false;
		}
		finally
		{
			$client->del($lockKey);
		}
	}
	
	/**
	 * Called by the runner after each test method
	 */
	#[Internal]
	#[Override]
	public function finalize(): void
	{
	}
	
	/**
	 * Called by the runner after all test methods have been invoked
	 */
	#[Internal]
	#[Override]
	public function deconstruct(): void
	{
	}
}
