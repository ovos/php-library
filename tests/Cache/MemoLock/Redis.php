<?php
declare(strict_types=1);

namespace Tests\Cache\MemoLock;

use Ovos\ArrayObject;
use Ovos\Container\Inject;
use Ovos\Cache\MemoLock\Redis as RedisMemoLock;
use Ovos\Cache\Redis\Functions;
use Ovos\Test;
use Ovos\Test\Internal;
use Ovos\Test\Cache\Store\TraitRedis;
use Closure;
use ErrorException;
use Override;
use Throwable;

use function microtime;
use function sprintf;

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
	
	/**
	 * RULE: a release whose call throws anything (a warning the error handler
	 * turned into an ErrorException) answers false - a request whose write
	 * succeeded never fails on its release, the lock goes with its TTL
	 */
	public function aReleaseThatThrowsIsLoggedNotThrown(): bool
	{
		return $this->withThrowingFunctions(
			fn(): bool => $this->memoLock->releaseActiveLock(self::KEY_ITEM) === false,
		);
	}
	
	/**
	 * RULE: a library that throws while loading for a write's own release
	 * hands out no call - the caller writes alone and releases after, its
	 * write never fails on the lock
	 */
	public function aReleaseCallWhoseLibraryThrowsIsNull(): bool
	{
		return $this->withThrowingFunctions(
			fn(): bool => $this->memoLock->releaseCall(self::KEY_ITEM) === null,
		);
	}
	
	/**
	 * Runs $check with the lock on KEY_ITEM held and a Functions that throws
	 * as a converted warning does; a throw out of $check fails it
	 */
	protected function withThrowingFunctions(
		Closure $check,
	): bool
	{
		$lockKey = $this->memoLock
			->getPrefixer()
			->prefix(RedisMemoLock::TYPE_LOCK, self::KEY_ITEM);
		[$functions, $connection] = (fn(): array => [$this->functions, $this->connection])->call($this->memoLock);
		$throwing = new class([], $connection) extends Functions
		{
			#[Override]
			public function loadLibraries(
				bool $replace = false,
			): bool
			{
				throw new ErrorException('is_file(): open_basedir restriction in effect');
			}
			
			#[Override]
			public function call(
				string $function,
				array $keys = [],
				array $args = [],
				bool $readOnly = false,
				bool $long = false,
			): mixed
			{
				throw new ErrorException('is_file(): open_basedir restriction in effect');
			}
		};
		
		try
		{
			$this->memoLock->tryLock(self::KEY_ITEM);
			(fn() => $this->functions = $throwing)->call($this->memoLock);
			
			return $check();
		}
		catch(Throwable)
		{
			return false;
		}
		finally
		{
			(fn() => $this->functions = $functions)->call($this->memoLock);
			// a lock the check left held goes, in this process and on the server
			$this->memoLock->releaseActiveLock(self::KEY_ITEM);
			$this->memoLock->getClient()->del($lockKey);
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
