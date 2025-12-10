<?php
declare(strict_types=1);

namespace Ovos\Store;

use Ovos\ArrayObject;
use APCUIterator;
use Closure;
use Throwable;

use function apcu_cache_info;
use function apcu_clear_cache;
use function apcu_delete;
use function apcu_entry;
use function apcu_exists;
use function apcu_fetch;
use function apcu_store;
use function array_key_exists;
use function bin2hex;
use function is_string;
use function mb_strtolower;
use function microtime;
use function random_bytes;
use function random_int;
use function usleep;

/**
 * Apcu
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
class Apcu extends KeyValue
{
	// Types
	public const string TYPE_LOCK = 'lock';
	
	// Queue (MemoLock) configuration
	protected bool $queueEnabled = true;
	
	protected int $queueLockTtlS = 1;
	
	protected int $queueWaitTimeoutS = 2;
	
	protected int $queueBackoffMinMs = 5;
	
	protected int $queueBackoffMaxMs = 25;
	
	/**
	 * An array of unique values for any active locks,
	 * indexed by the prefixed cache id
	 */
	protected array $queueLocks = [];
	
	public function __construct(
		?string $prefix = null,
		?string $group = null,
		?ArrayObject $config = null,
	)
	{
		parent::__construct();
		
		// this store does not rely on config availability on purpose
		
		$this->setPrefix($prefix);
		$this->setGroup($group);
		$this->setConfig($config);
		
		if($config !== null)
		{
			if($compression = $config->offsetGet('compression'))
			{
				$this->setCompression($compression);
			}
			if($queue = $config->offsetGet('queue'))
			{
				$this->setQueue($queue);
			}
		}
	}
	
	public static function fromConfig(
		ArrayObject $config,
		?string $group = null,
	): static
	{
		return new static
		(
			$config->prefix,
			$group ?? self::GROUP_DEFAULT,
			$config->perishable,
		);
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
	
	public function setQueueEnabled(
		bool $enabled,
	): static
	{
		$this->queueEnabled = $enabled;
		
		return $this;
	}
	
	public function isQueueEnabled(): bool
	{
		return $this->queueEnabled;
	}
	
	/**
	 * Returns "id" to be used as cache id form a path string
	 * For example: /home/user/my-file.txt -> user-my-file-txt
	 * or C:\Users\User\Desktop\my-file.txt -> user-my-file-txt
	 */
	public static function pathToId(
		string $string,
	): string
	{
		$string = mb_strtolower($string);
		
		if(substr($string, 1, 2) === ':\\') // windows drive
		{
			$string = substr($string, 3);
		}
		
		$string = str_replace([
			'/',
			'\\',
			'.', // dot
		], '-', $string);
		
		$string = trim($string, '-');
		
		return $string;
	}
	
	public function set(
		string $key,
		mixed $value,
		int $ttl = 0,
	): bool
	{
		$id = $this->prefix($key, $this->getGroup());
		$value = $this->compress($this->serialize($value));
		
		try
		{
			return apcu_store($id, $value, $ttl);
		}
		finally
		{
			$this->releaseActiveLock($key, $id);
		}
	}
	
	public function get(
		string $key,
		?Closure $resolver = null,
		int $ttl = 0,
		?bool $queue = null, // override for the config switch
		?int $queueLockTtlS = null, // override for the config value
	): mixed
	{
		$id = $this->prefix($key, $this->getGroup());
		
		$value = apcu_fetch($id);
		if($value !== false)
		{
			return $this->unserialize($this->decompress($value));
		}
		
		// cache miss, queue logic begins
		return $this->queue(
			$key,
			$id,
			$resolver,
			$ttl,
			$queue,
			$queueLockTtlS,
		);
	}
	
	public function lockAndQueue(
		string $key,
		?Closure $resolver = null,
		int $ttl = 0,
		?int $queueLockTtlS = null, // override for the config value
		bool $lockOnly = false,
	): mixed
	{
		$id = $this->prefix($key, $this->getGroup());
		
		return $this->queue(
			$key,
			$id,
			$resolver,
			$ttl,
			true,
			$queueLockTtlS,
			$lockOnly,
		);
	}
	
	protected function queue(
		string $key,
		string $id,
		?Closure $resolver = null,
		int $ttl = 0,
		?bool $queue = null, // override for the config switch
		?int $queueLockTtlS = null, // override for the config value
		bool $lockOnly = false,
	): mixed
	{
		if(($this->queueEnabled === false && $queue !== true)
			|| ($this->queueEnabled === true && $queue === false)
		)
		{
			return $this->setFromResolver($key, $resolver, $ttl);
		}
		
		$lockKey = $this->prefix(self::TYPE_LOCK, $id);
		$lockValue = bin2hex(random_bytes(16));
		$queueLockTtlS = $queueLockTtlS ?? $this->queueLockTtlS;
		
		$lockAcquired = false;
		apcu_entry($lockKey, static function() use (&$lockAcquired, $lockValue)
		{
			$lockAcquired = true;
			
			return $lockValue;
		}, $queueLockTtlS);
		
		if($lockAcquired)
		{
			// lock acquired, store it internally for releaseActiveLock()
			return $this->lockAcquired($key,
				$id,
				$lockValue,
				$resolver,
				$ttl,
			);
		}
		
		// lock not acquired
		$startTime = microtime(true);
		while((microtime(true) - $startTime) < $this->queueWaitTimeoutS)
		{
 			// wait with a short, randomized backoff
			usleep(random_int(
				$this->queueBackoffMinMs,
				$this->queueBackoffMaxMs,
			) * 1000);
			
			if($lockOnly === false)
			{
				// check if data is already there
				$value = apcu_fetch($id);
				if($value !== false)
				{
					return $this->unserialize($this->decompress($value));
				}
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
					return $this->lockAcquired($key,
						$id,
						$lockValue,
						$resolver,
						$ttl,
					);
				}
			}
			
			// if the lock still exists, or we failed to acquire it, loop to wait again
		}
		
		// we tried, time to fetch the data ourselves
		return $this->callResolver($resolver);
	}
	
	protected function lockAcquired(
		string $key,
		string $id,
		string $lockValue,
		?Closure $resolver = null,
		int $ttl = 0,
	): mixed
	{
		$this->queueLocks[$id] = $lockValue;
		
		try
		{
			return $this->setFromResolver($key, $resolver, $ttl);
		}
		catch(Throwable $throwable)
		{
			$this->releaseActiveLock($key, $id);
			
			throw $throwable;
		}
	}
	
	/**
	 * This function can be used to release a lock acquired by
	 * the get(), for example, when an exception is caught,
	 * and we know that save() won't be called
	 * This will enable other processes to acquire the lock faster
	 */
	public function releaseActiveLock(
		string $key,
		?string $id = null,
	): bool
	{
		if($id === null)
		{
			$id = $this->prefix($key, $this->getGroup());
		}
		
		if(array_key_exists($id, $this->queueLocks) === false)
		{
			return false;
		}
		
		$lockValue = $this->queueLocks[$id];
		unset($this->queueLocks[$id]);
		
		$lockKey = $this->prefix(self::TYPE_LOCK, $id);
		
		// verify lock ownership before deletion
		$lockKeyValue = apcu_fetch($lockKey);
		if($lockKeyValue !== false
			&& $lockKeyValue === $lockValue)
		{
			return apcu_delete($lockKey);
		}
		
		// lock doesn't exist or belongs to another process
		return false;
	}
	
	/**
	 * This function should be used for long-running processes
	 * which hold the lock for longer than default lock TTL
	 */
	public function renewLock(
		string $key,
		?int $ttlS = null,
	): bool
	{
		$id = $this->prefix($key, $this->getGroup());
		
		if(array_key_exists($id, $this->queueLocks) === false)
		{
			return false;
		}
		
		$lockKey = $this->prefix(self::TYPE_LOCK, $id);
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
	
	public function delete(
		string|APCUIterator $key,
	): bool
	{
		if(is_string($key))
		{
			$key = $this->prefix($key, $this->getGroup());
		}
		
		return apcu_delete($key);
	}
	
	public function info(
		bool $limited = false,
	): bool|array
	{
		return apcu_cache_info($limited);
	}
	
	public function clear(): bool // always true
	{
		return apcu_clear_cache();
	}
}
