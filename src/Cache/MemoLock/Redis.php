<?php
declare(strict_types=1);

namespace Ovos\Cache\MemoLock;

use Ovos\ArrayObject;
use Ovos\Invoker;
use Ovos\Cache\MemoLock;
use Ovos\Cache\Prefixer;
use Ovos\Cache\Redis\Functions;
use Ovos\Connection\RedisCommon as Connection;
use Closure;
use Redis as RedisClient;
use RedisCluster as RedisClusterClient;
use RedisException;
use RedisClusterException;

use function bin2hex;
use function random_bytes;
use function min;
use function random_int;
use function array_key_exists;

use const PHP_EOL;

/**
 * Redis
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Redis extends MemoLock
{
	public const string TYPE_LOCK = 'lock';
	public const string TYPE_CHANNEL = 'channel';
	
	// Libraries
	/**
	 * An array of function libraries used by this class
	 */
	public const array LIBRARIES = [
		'memolock' => 'MemoLock.lua',
	];
	
	protected Connection $connection;
	
	protected Connection $queueConnection;
	
	protected Functions $functions;
	
	// queue
	protected int $queueLockTtlMs = 2000;
	
	protected int $queueWaitAttempts = 3;
	
	public function __construct(
		Connection $connection,
		Connection $queueConnection,
		?string $prefix = null,
		?ArrayObject $config = null,
		?object $context = null,
	)
	{
		$this->connection = $connection;
		$this->queueConnection = $queueConnection;
		
		$this->invoker = new Invoker($context ?? $this);
		$this->prefixer = new Prefixer(
			$prefix,
		);
		
		$this->functions = new Functions(
			static::LIBRARIES,
			$connection,
			$prefix,
		);
		
		parent::__construct($config);
	}
	
	public function setQueue(
		ArrayObject $config,
	): static
	{
		if(($enabled = $config->offsetGet('enabled')) !== null)
		{
			$this->queueEnabled = $enabled;
		}
		if(($lockTtlMs = $config->offsetGet('lock_ttl_ms')) !== null)
		{
			$this->queueLockTtlMs = $lockTtlMs;
		}
		if(($waitAttempts = $config->offsetGet('wait_attempts')) !== null)
		{
			$this->queueWaitAttempts = $waitAttempts;
		}
		
		return $this;
	}
	
	public function getClient(): RedisClient|RedisClusterClient|null
	{
		return $this->connection->getClient();
	}
	
	/**
	 * Pub/sub should use a standalone (non-cluster) connection,
	 * a classic PUBLISH is broadcast cluster-wide anyway
	 * Connects on demand - the queue connection is only ever needed
	 * under contention, so it must not cost anything on other requests
	 */
	public function getQueueClient(): ?RedisClient
	{
		$client = $this->queueConnection->getClient();
		if($client === null)
		{
			$this->queueConnection->connect();
			$client = $this->queueConnection->getClient();
		}
		
		return $client;
	}
	
	public function lockAndQueue(
		string $id,
		?Closure $fetcher = null, // no fetcher = lock-only mode
		?Closure $resolver = null,
		?bool $queue = null,
		?int $queueLockTtlMs = null,
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
		
		if(($client = $this->getClient()) === null)
		{
			return $this->invoker
				->invoke($resolver);
		}
		
		$lockKey = $this->prefixer
			->prefix(static::TYPE_LOCK, $id);
		$channelName = $this->prefixer
			->prefix(static::TYPE_CHANNEL, $id);
		$lockValue = bin2hex(random_bytes(16));
		$queueLockTtlMs = $queueLockTtlMs ?? $this->queueLockTtlMs;
		
		$this->debug('queue: ' . $id);
		
		// try to acquire a distributed lock; a Redis failure here must not
		// break the host application - degrade to resolving the value directly
		try
		{
			$lockAcquired = $client->set($lockKey, $lockValue, [
				'NX',
				'PX' => $queueLockTtlMs,
			]);
		}
		catch(RedisException|RedisClusterException $exception)
		{
			$this->connection->log($exception);
			
			return $this->invoker->invoke($resolver);
		}
		
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
		
		if($this->getQueueClient() === null)
		{
			return $this->invoker
				->invoke($resolver);
		}
		
		// lock was not acquired, wait for a publication result from another request
		$waitTimeMs = $waitTimeJitterMs = $queueLockTtlMs;
		for($attempt = 0; $attempt < $this->queueWaitAttempts; $attempt++)
		{
			// a crashed producer never publishes - never wait (much) longer
			// than its lock can live (mirrors waitForRelease)
			$remainingMs = 0;
			try
			{
				$remainingMs = $client->pttl($lockKey);
			}
			catch(RedisException|RedisClusterException $exception)
			{
				$this->connection->log($exception);
			}
			
			if($remainingMs === -2 || $remainingMs === false)
			{
				// the lock is gone: no publication will come - skip the
				// subscribe and check the value / race for the lock now
				$success = false;
			}
			else
			{
				$success = $this->waitForMessage($channelName,
					$remainingMs > 0
						? min($waitTimeJitterMs, $remainingMs + 25)
						: $waitTimeJitterMs,
				);
			}
			if($success === false)
			{
				// shorten the wait time on the next attempt
				$waitTimeMs/= 2;
				// add jitter to the wait time (0-50%)
				$waitTimeJitterMs = $waitTimeMs
					+ random_int(0, (int)($waitTimeMs * 0.5));
			}
			
			// either we got the message or we timed-out
			
			// lock-only mode
			if($fetcher === null && $success === true)
			{
				return true;
			}
			
			// check if the result was produced while we were waiting
			if(($result = $this->invoker
				->invoke($fetcher)) !== null)
			{
				return $result;
			}
			
			try
			{
				// check if the lock still exists
				if($client->exists($lockKey) === 0)
				{
					// try to acquire a distributed lock
					$lockAcquired = $client->set($lockKey, $lockValue, [
						'NX',
						'PX' => $queueLockTtlMs,
					]);
					
					if($lockAcquired)
					{
						$this->debug('timeout & lock acquired: ' . $id);
						
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
			}
			catch(RedisException|RedisClusterException $exception)
			{
				$this->connection->log($exception);
				
				return $this->invoker->invoke($resolver);
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
	 * Waits for a single publication on a channel or a timeout,
	 * blocking the queue (pub/sub) connection for up to the given time
	 */
	protected function waitForMessage(
		string $channelName,
		float|int $waitTimeMs,
	): bool
	{
		if(($queueClient = $this->getQueueClient()) === null)
		{
			return false;
		}
		
		// set the read timeout to the requested wait time
		$this->queueConnection->toggleReadTimeout(
			Connection::TIMEOUT_READ_CUSTOM,
			$waitTimeMs / 1000, // milliseconds to seconds
			false,
		);
		
		try
		{
			$this->debug('subscribe: ' . $channelName);
			
			// block and wait for a message on the channel or a timeout (when no message is received)
			$queueClient->subscribe([$channelName],
				function($client, $channelName, $message)
				{
					$this->debug('unsubscribe: ' . $channelName);
					
					$client->unsubscribe([$channelName]);
				}
			);
			
			return true;
		}
		// we got no message, redis responded with "RedisException: read error on connection"
		catch(RedisException|RedisClusterException $exception)
		{
			$queueClient->unsubscribe([$channelName]);
			
			$this->debug('timeout: ' . $channelName
				. PHP_EOL . $exception->getMessage(), timeout: true);
			
			return false;
		}
		finally
		{
			// restore the default read timeout
			$this->queueConnection->toggleReadTimeout();
		}
	}
	
	/**
	 * Waits for a lock (typically held by another request) to be released
	 * WITHOUT acquiring it: subscribes to the lock's channel and returns once
	 * the holder publishes the release, or the lock expires or vanishes
	 * Returns true when the lock is gone (or held by this very request),
	 * false when it still exists after all wait attempts
	 */
	public function waitForRelease(
		string $id,
		?int $queueLockTtlMs = null,
	): bool
	{
		// a lock held by this very request never blocks it
		if(array_key_exists($id, $this->queueLocks))
		{
			return true;
		}
		
		if(($client = $this->getClient()) === null)
		{
			return true;
		}
		
		$lockKey = $this->prefixer
			->prefix(static::TYPE_LOCK, $id);
		$channelName = $this->prefixer
			->prefix(static::TYPE_CHANNEL, $id);
		$queueLockTtlMs = $queueLockTtlMs ?? $this->queueLockTtlMs;
		
		$waitTimeMs = $waitTimeJitterMs = $queueLockTtlMs;
		for($attempt = 0; $attempt <= $this->queueWaitAttempts; $attempt++)
		{
			// a redis failure while checking must not block the host application
			try
			{
				$remainingMs = $client->pttl($lockKey);
			}
			catch(RedisException|RedisClusterException $exception)
			{
				$this->connection->log($exception);
				
				return true;
			}
			
			if($remainingMs === -2 || $remainingMs === false)
			{
				// the lock is gone
				return true;
			}
			
			// the last iteration only re-checks the lock, it does not wait again
			if($attempt === $this->queueWaitAttempts)
			{
				break;
			}
			
			$this->debug('wait for release: ' . $id);
			
			// a crashed holder never publishes - never wait (much) longer
			// than the lock itself can live
			$waitMs = $remainingMs > 0
				? min($waitTimeJitterMs, $remainingMs + 25)
				: $waitTimeJitterMs;
			
			$success = $this->waitForMessage($channelName, $waitMs);
			if($success === false)
			{
				// shorten the wait time on the next attempt
				$waitTimeMs/= 2;
				// add jitter to the wait time (0-50%)
				$waitTimeJitterMs = $waitTimeMs
					+ random_int(0, (int)($waitTimeMs * 0.5));
			}
		}
		
		$this->debug('wait for release timed out: ' . $id, timeout: true);
		
		return false;
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
		$channelName = $this->prefixer
			->prefix(static::TYPE_CHANNEL, $id);
		
		// atomically release the lock and notify any waiters using the Lua script;
		// the channel is passed as an ARG (not a KEY) so the call stays single-slot
		// and works on Redis Cluster (see memolock_release_lock_and_publish)
		try
		{
			$released = (bool)$this->functions
				->call('memolock_release_lock_and_publish',
					[$lockKey],
					[$lockValue, $channelName],
			);
		}
		catch(RedisException|RedisClusterException $exception)
		{
			// a release failure must not break a request whose write
			// succeeded; the lock expires on its PX TTL anyway
			$this->connection->log($exception);
			
			return false;
		}
		
		$this->debug(
			$released
				? 'lock released: ' . $id
				: 'lock release failed: ' . $id
		);
		
		return $released;
	}
	
	/**
	 * This function should be used for long-running processes
	 * which hold the lock for longer than default lock TTL
	 */
	public function renewLock(
		string $id,
		?int $ttlMs = null,
	): bool
	{
		if(array_key_exists($id, $this->queueLocks) === false)
		{
			return false;
		}
		
		$lockKey = $this->prefixer
			->prefix(self::TYPE_LOCK, $id);
		$lockValue = $this->queueLocks[$id];
		$ttlMs = $ttlMs ?? $this->queueLockTtlMs;
		
		try
		{
			return (bool)$this->functions
				->call('memolock_renew_lock',
					[$lockKey],
					[$lockValue, $ttlMs],
			);
		}
		catch(RedisException|RedisClusterException $exception)
		{
			$this->connection->log($exception);
			
			return false;
		}
	}
}
