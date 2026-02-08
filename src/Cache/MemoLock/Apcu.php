<?php
declare(strict_types=1);

namespace Ovos\Cache\MemoLock;

use Ovos\ArrayObject;
use Ovos\Invoker;
use Ovos\Cache\MemoLock;
use Ovos\Cache\Prefixer;
use Closure;

use function array_key_exists;
use function apcu_entry;
use function apcu_exists;
use function bin2hex;
use function microtime;
use function random_bytes;
use function random_int;
use function usleep;

/**
 * Apcu
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Apcu extends MemoLock
{
	public const string TYPE_LOCK = 'lock';
	
	protected int $queueLockTtlS = 1;
	
	protected int $queueWaitTimeoutS = 2;
	
	protected int $queueBackoffMinMs = 5;
	
	protected int $queueBackoffMaxMs = 25;
	
	public function __construct(
		?string $prefix = null,
		?ArrayObject $config = null,
		?object $context = null,
	)
	{
		$this->invoker = new Invoker($context ?? $this);
		$this->prefixer = new Prefixer(
			$prefix,
		);
		
		parent::__construct($config);
	}
	
	public function setQueue(
		ArrayObject $config,
	): static
	{
		if(($enabled = $config->offsetGet('enabled')) !== null) // true or false
		{
			$this->queueEnabled = $enabled;
		}
		if(($lockTtlS = $config->offsetGet('lock_ttl_s')) !== null)
		{
			$this->queueLockTtlS = $lockTtlS;
		}
		if(($waitTimeoutS = $config->offsetGet('wait_timeout_s')) !== null)
		{
			$this->queueWaitTimeoutS = $waitTimeoutS;
		}
		if(($backoffMinMs = $config->offsetGet('backoff_min_ms')) !== null)
		{
			$this->queueBackoffMinMs = $backoffMinMs;
		}
		if(($backoffMaxMs = $config->offsetGet('backoff_max_ms')) !== null)
		{
			$this->queueBackoffMaxMs = $backoffMaxMs;
		}
		
		return $this;
	}
	
	public function lockAndQueue(
		string $id,
		?Closure $fetcher = null, // no fetcher = lock-only mode
		?Closure $resolver = null,
		?bool $queue = null,
		?int $queueLockTtlS = null,
	): mixed
	{
		if(($this->queueEnabled === false && $queue !== true)
			|| ($this->queueEnabled === true && $queue === false)
		)
		{
			// queue disabled globally or explicitly disabled for this call
			return $this->invoker
				->invoke($resolver);
		}
		
		$lockKey = $this->prefixer
			->prefix(static::TYPE_LOCK, $id);
		$lockValue = bin2hex(random_bytes(16));
		$queueLockTtlS = $queueLockTtlS ?? $this->queueLockTtlS;
		
		$this->debug('queue: ' . $id);
		
		$lockAcquired = false;
		apcu_entry($lockKey, static function() use (&$lockAcquired, $lockValue)
		{
			$lockAcquired = true;
			
			return $lockValue;
		}, $queueLockTtlS);
		
		if($lockAcquired)
		{
			$this->debug('lock acquired: ' . $id);
			
			// lock acquired, store it internally for releaseActiveLock()
			return $this->lockAcquired(
				$id,
				$lockValue,
				$resolver,
			);
		}
		
		// lock was not acquired
		$startTime = microtime(true);
		while((microtime(true) - $startTime) < $this->queueWaitTimeoutS)
		{
 			// wait with a short, randomized backoff
			usleep(random_int(
				$this->queueBackoffMinMs,
				$this->queueBackoffMaxMs,
			) * 1000);
			
			// check if the result was produced while we were waiting
			if(($result = $this->invoker
				->invoke($fetcher)) !== null)
			{
				return $result;
			}
			
			// check if the lock still exists
			if(apcu_exists($lockKey) === false)
			{
				$lockAcquired = false;
				apcu_entry($lockKey, static function() use (&$lockAcquired, $lockValue)
				{
					$lockAcquired = true;
					
					return $lockValue;
				}, $queueLockTtlS);
				
				if($lockAcquired)
				{
					// lock acquired, store it internally for releaseActiveLock()
					return $this->lockAcquired(
						$id,
						$lockValue,
						$resolver,
					);
				}
			}
			else
			{
				$this->debug('lock still exists for: ' . $id);
			}
			
			// if the lock still exists, or we failed to acquire it, loop to wait again
		}
		
		$this->debug('queue, timeout & returning value: ' . $id);
		
		// we tried, time to fetch the data ourselves
		// even if we timed out, we proceed as if we acquired the lock,
		// this allows the process to work and notify other waiters when it's finished
		return $this->lockAcquired(
			$id,
			$lockValue,
			$resolver,
		);
	}
	
	/**
	 * This function can be used to release a lock acquired by
	 * the get(), for example, when an exception is caught,
	 * and we know that save() won't be called
	 * This will enable other processes to acquire the lock faster
	 */
	public function releaseActiveLock(
		string $id,
	): bool
	{
		$this->debug('release lock: ' . $id, true);
		
		if(array_key_exists($id, $this->queueLocks) === false)
		{
			return false;
		}
		
		$lockValue = $this->queueLocks[$id];
		unset($this->queueLocks[$id]);
		
		$lockKey = $this->prefixer
			->prefix(static::TYPE_LOCK, $id);
		
		$released = $this->lockReleased($lockKey, $lockValue);
		
		$this->debug(
			$released
				? 'lock released: ' . $id
				: 'lock release failed: ' . $id
		);
		
		return $released;
	}
	
	protected function lockReleased(
		string $lockKey,
		string $lockValue,
	): bool
	{
		$lockKeyValue = apcu_fetch($lockKey);
		// verify lock ownership before deletion
		if($lockKeyValue !== false
			&& $lockKeyValue === $lockValue)
		{
			return apcu_delete($lockKey);
		}
		
		// when false = lock doesn't exist or belongs to another process
		return false;
	}
	
	/**
	 * This function should be used for long-running processes
	 * which hold the lock for longer than default lock TTL
	 */
	public function renewLock(
		string $id,
		?int $ttlS = null,
	): bool
	{
		if(array_key_exists($id, $this->queueLocks) === false)
		{
			return false;
		}
		
		$lockKey = $this->prefixer
			->prefix(static::TYPE_LOCK, $id);
		$lockValue = $this->queueLocks[$id];
		$ttlS = $ttlS ?? $this->queueLockTtlS;
		
		// verify lock ownership before extension
		$lockKeyValue = apcu_fetch($lockKey);
		if($lockKeyValue !== false
			&& $lockKeyValue === $lockValue)
		{
			return apcu_store($lockKey, $lockValue, $ttlS);
		}
		
		return false;
	}
}
