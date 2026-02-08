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
