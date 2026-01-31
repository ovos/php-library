<?php
declare(strict_types=1);

namespace Ovos\Store\KeyValue;

use Override;
use Ovos\ArrayObject;
use Ovos\Connection\Redis as Connection;
use Closure;
use Throwable;
use Redis as RedisClient;
use RedisException;

use function array_key_exists;
use function array_slice;
use function bin2hex;
use function ceil;
use function count;
use function file_get_contents;
use function is_int;
use function random_bytes;
use function str_replace;

/**
 * Redis
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
abstract class Redis extends Tags
{
	// Separators
	public const string SEPARATOR_FUNCTION = '_';
	
	protected ?string $functionPrefix = null;
	
	// Keys
	public const string KEY_DATA = 'data';
	public const string KEY_TAGS = 'tags';
	
	/**
	 * Statuses
	 *
	 * Used for rawCommand, which returns strings instead of boolean values when OPT_REPLY_LITERAL is enabled
	 * @see https://github.com/phpredis/phpredis/issues/1550
	 */
	public const string STATUS_OK = 'OK';
	
	// Types
	public const string TYPE_ITEMS = 'items';
	public const string TYPE_LOCK = 'lock';
	public const string TYPE_CHANNEL = 'channel';
	
	/**
	 * Redis connection
	 */
	protected Connection $connection;
	
	protected int $multiMode = RedisClient::PIPELINE;
	
	// Libraries
	/**
	 * An array of function libraries used by this class
	 */
	public const array LIBRARIES = [
		'store' =>
			'Lua'
			. DIRECTORY_SEPARATOR . 'Redis.lua',
	];
	
	protected array $librariesLoaded = [];
	
	// Queue (MemoLock) configuration
	protected bool $queueEnabled = false;
	
	protected int $queueLockTtlMs = 2000;
	
	protected int $queueWaitAttempts = 3;
	
	/**
	 * An array of unique values for any active locks,
	 * indexed by the prefixed cache id
	 */
	protected array $queueLocks = [];
	
	public function __construct(
		Connection $connection,
		ArrayObject $config,
		?string $prefix = null,
		?string $group = null,
	)
	{
		parent::__construct();
		
		$this->setConnection($connection);
		$this->setConfig($config);
		$this->setPrefix($prefix);
		$this->setGroup($group);
		
		if($storeOptions = $this->config->offsetGet('store_options'))
		{
			$this->setStoreOptions($storeOptions);
		}
		
		if($compression = $this->config->offsetGet('compression'))
		{
			$this->setCompression($compression);
		}
		
		if($queue = $this->config->offsetGet('queue'))
		{
			$this->setQueue($queue);
		}
	}
	
	public function setPrefix(
		?string $prefix = null,
	): static
	{
		$this->prefix = $prefix;
		
		if($prefix !== null)
		{
			$this->functionPrefix = str_replace
			(
				[self::SEPARATOR_PREFIX, '-'],
				[self::SEPARATOR_FUNCTION, self::SEPARATOR_FUNCTION],
				$prefix,
			);
		}
		
		return $this;
	}
	
	public function setConnection(
		Connection $connection,
	): static
	{
		$this->connection = $connection;
		
		return $this;
	}
	
	public function getConnection(): Connection
	{
		return $this->connection;
	}
	
	public function setStoreOptions(
		ArrayObject $options,
	): static
	{
		return $this;
	}
	
	public function setQueue(
		ArrayObject $config,
	): static
	{
		if(($enabled = $config->offsetGet('enabled')) !== null) // true or false
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
	
	public function functionPrefix(
		string $key,
		?string $prefix = null,
		string $separator = self::SEPARATOR_FUNCTION,
	): string
	{
		$prefix = $prefix ?? $this->functionPrefix;
		
		return $prefix !== null
			? $prefix . $separator . $key
			: $key;
	}
	
	public static function fromConfig(
		Connection $connection,
		ArrayObject $config,
		?string $group = null,
	): static
	{
		return new static
		(
			$connection,
			$config->persistent,
			$config->prefix,
			$group ?? self::GROUP_DEFAULT,
		);
	}
	
	public function getClient(): ?RedisClient
	{
		return $this->connection->getClient();
	}
	
	public function getType(
		string $type = self::TYPE_ITEMS,
	): string
	{
		return $this->prefix($type, $this->getGroup());
	}
	
	/**
	 * Ensures that all the libraries of scripts are loaded into redis
	 */
	public function loadLibraries(
		bool $replace = false,
	): bool
	{
		foreach(static::LIBRARIES as $libraryName => $libraryFile)
		{
			if($this->loadLibrary($libraryName, $libraryFile, $replace) === false)
			{
				return false;
			}
		}
		
		return true;
	}
	
	/**
	 * Ensures that a library of scripts is loaded into redis
	 * Library name and functions cannot use ":" character in their names (this includes also the prefix):
	 * "ERR Library names can only contain letters, numbers, or underscores(_) and must be at least one character"
	 */
	public function loadLibrary(
		string $libraryName,
		string $libraryFile,
		bool $replace = false,
	): bool
	{
		$libraryName = $this->functionPrefix($libraryName);
		
		if(isset($this->librariesLoaded[$libraryName])
			&& $this->librariesLoaded[$libraryName] === true
			&& $replace === false)
		{
			return true;
		}
		
		if(($client = $this->getClient()) === null)
		{
			return false;
		}
		
		// if we force a replacement, no need to detect if a library is loaded
		if($replace === false)
		{
			$list = $client->function('list', 'libraryname', $libraryName);
			
			if($list !== false
				&& isset($list[0])
				&& $list[0]['library_name'] === $libraryName
			)
			{
				$this->librariesLoaded[$libraryName] = true;
				
				return true;
			}
		}
		
		$client->clearLastError();
		
		$functions = file_get_contents(__DIR__
			. DIRECTORY_SEPARATOR . 'Redis'
			. DIRECTORY_SEPARATOR . $libraryFile,
		);
		
		$functions = str_replace('[prefix]',
			$this->functionPrefix
				? $this->functionPrefix . self::SEPARATOR_FUNCTION
				: '',
			$functions,
		);
		
		$library = "#!lua name=" . $libraryName . PHP_EOL . PHP_EOL
			. $functions;
		
		$libraryLoaded = $replace
			? $client->function('load', 'replace', $library)
			: $client->function('load', $library);
		
		if($error = $client->getLastError())
		{
			throw new RedisException($error);
		}
		
		if($libraryLoaded === $libraryName)
		{
			$this->librariesLoaded[$libraryName] = true;
			
			return true;
		}
		
		return false;
	}
	
	protected function functionCall(
		string $function,
		array $keys = [],
		array $args = [],
		bool $readOnly = false,
		bool $long = false,
	): mixed
	{
		if(($client = $this->getClient()) === null)
		{
			return false;
		}
		
		$this->loadLibraries();
		
		if($long)
		{
			$this->connection->toggleReadTimeout(Connection::TIMEOUT_READ_LONG);
		}
		
		$call = $readOnly
			? 'fcall_ro'
			: 'fcall'
		;
		$functionName = $this->functionPrefix($function);
		
		$result = $this->connection->slowLog([$client, $call], $functionName, $keys, $args);
		
		if($long)
		{
			$this->connection->toggleReadTimeout();
		}
		
		return $result;
	}
	
	protected function batchFunctionCall(
		string $function,
		array $keys = [],
		array $args = [],
		bool $readOnly = false,
		bool $long = false,
		int $batchSize = 1000,
	): void
	{
		if($long)
		{
			// an extended timeout will be valid through all calls of the batch
			$this->connection->toggleReadTimeout(Connection::TIMEOUT_READ_LONG);
		}
		
		$countKeys = count($keys);
		$totalBatches = (int)ceil($countKeys / $batchSize);
		
		for($batch = 0; $batch < $totalBatches; $batch++)
		{
			$keysBatch = array_slice($keys, $batch * $batchSize, $batchSize);
			$this->functionCall($function, $keysBatch, $args, $readOnly);
		}
		
		if($long)
		{
			$this->connection->toggleReadTimeout();
		}
	}
	
	#[Override]
	public function get(
		string $key,
		?Closure $resolver = null,
		int $ttl = 0,
		array $tags = [],
		?bool $queue = null, // override for the config switch
		?int $queueLockTtlMs = null, // override for the config value
	): mixed
	{
		if(($client = $this->getClient()) === null)
		{
			return $this->callResolver($resolver);
		}
		
		$id = $this->prefix($key, $this->getType());
		
		try
		{
			$value = $client->hGet(
				$id,
				self::KEY_DATA,
			);
			if($value !== false)
			{
				return $this->unserialize($this->decompress($value));
			}
		}
		catch(RedisException $exception)
		{
			$this->log($exception);
			
			return $this->callResolver($resolver);
		}
		
		// cache miss, queue logic begins
		return $this->queue(
			$client,
			$key,
			$id,
			$resolver,
			$ttl,
			$tags,
			$queue,
			$queueLockTtlMs,
		);
	}
	
	public function lockAndQueue(
		string $key,
		?Closure $resolver = null,
		int $ttl = 0,
		array $tags = [],
		?int $queueLockTtlMs = null, // override for the config value
		bool $lockOnly = false,
	): mixed
	{
		if(($client = $this->getClient()) === null)
		{
			return $this->callResolver($resolver);
		}
		
		$id = $this->prefix($key, $this->getType());
		
		return $this->queue(
			$client,
			$key,
			$id,
			$resolver,
			$ttl,
			$tags,
			true,
			$queueLockTtlMs,
			$lockOnly,
		);
	}
	
	protected function queue(
		RedisClient $client,
		string $key,
		string $id,
		?Closure $resolver = null,
		int $ttl = 0,
		array $tags = [],
		?bool $queue = null, // override for the config switch
		?int $queueLockTtlMs = null, // override for the config value
		bool $lockOnly = false,
	): mixed
	{
		if(($this->queueEnabled === false && $queue !== true)
			|| ($this->queueEnabled === true && $queue === false)
		)
		{
			return $this->setFromResolver($key, $resolver, $ttl, $tags);
		}
		
		$lockKey = $this->prefix(self::TYPE_LOCK, $id);
		$channelName = $this->prefix(self::TYPE_CHANNEL, $id);
		$lockValue = bin2hex(random_bytes(16));
		$queueLockTtlMs = $queueLockTtlMs ?? $this->queueLockTtlMs;
		
		$this->connection->debug('queue: ' . $id);
		
		// try to acquire a distributed lock
		$lockAcquired = $client->set($lockKey, $lockValue, [
			'NX',
			'PX' => $queueLockTtlMs,
		]);
		
		if($lockAcquired)
		{
			$this->connection->debug('lock acquired: ' . $id);
			
			// lock acquired, store it internally for releaseActiveLock()
			return $this->lockAcquired($key,
				$id,
				$lockValue,
				$resolver,
				$ttl,
				$tags,
			);
		}
		
		// lock not acquired
		$waitTimeMs = $waitTimeJitterMs = $queueLockTtlMs;
		for($attempt = 0; $attempt < $this->queueWaitAttempts; $attempt++)
		{
			// set read timeout to the requested queue lock TTL
			$this->connection->toggleReadTimeout(
				Connection::TIMEOUT_READ_CUSTOM,
				$waitTimeJitterMs / 1000, // milliseconds to seconds
				false,
			);
			
			$success = false;
			
			try
			{
				$this->connection->debug('subscribe: ' . $id);
				
				// block and wait for a message on the channel or a timeout (when no message is received)
				$success = $client->subscribe([$channelName],
					function($client, $channelName, $message) use ($id)
					{
						$this->connection->debug(
							'unsubscribe: ' . $id);
						
						$client->unsubscribe([$channelName]);
					}
				);
			}
			// we got no message, redis responded with "RedisException: read error on connection"
			catch(RedisException $exception)
			{
				$client->unsubscribe([$channelName]);
				// shorten the wait time on the next attempt
				$waitTimeMs/= 2;
				// add jitter to the wait time (0-50%)
				$waitTimeJitterMs = $waitTimeMs
					+ random_int(0, (int)($waitTimeMs * 0.5));
				
				$this->connection->debug('timeout: ' . $id
					. PHP_EOL . $exception->getMessage(), timeout: true);
			}
			finally
			{
				// restore the default read timeout
				$this->connection->toggleReadTimeout();
			}
			
			// either we got the message or we timed-out
			try
			{
				if($lockOnly === true && $success === true)
				{
					return true;
				}
				
				if($lockOnly === false)
				{
					// check if data is already there
					$value = $client->hGet(
						$id,
						self::KEY_DATA,
					);
					if($value !== false)
					{
						$this->connection->debug(
							'timeout & data found: ' . $id);
						
						return $this->unserialize($this->decompress($value));
					}
				}
				
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
						$this->connection->debug(
							'timeout & lock acquired: ' . $id);
						
						// lock acquired, store it internally for releaseActiveLock()
						return $this->lockAcquired($key,
							$id,
							$lockValue,
							$resolver,
							$ttl,
							$tags,
						);
					}
				}
				else
				{
					$this->connection->debug(
						'lock still exists for: ' . $id);
				}
			}
			catch(RedisException $exception)
			{
				$this->log($exception);
			}
			// if the lock still exists, or we failed to acquire it, loop to wait again
		}
		
		$this->connection->debug(
			'queue, timeout & returning value: ' . $id);
		
		// we tried, time to fetch the data ourselves
		// even if we timed out, we proceed as if we acquired the lock,
		// this allows the process to work and notify other waiters when it's finished
		return $this->lockAcquired($key,
			$id,
			$lockValue,
			$resolver,
			$ttl,
			$tags,
		);
	}
	
	protected function lockAcquired(
		string $key,
		string $id,
		string $lockValue,
		?Closure $resolver = null,
		int $ttl = 0,
		array $tags = [],
	): mixed
	{
		$this->queueLocks[$id] = $lockValue;
		
		try
		{
			return $this->setFromResolver($key, $resolver, $ttl, $tags);
		}
		catch(Throwable $throwable)
		{
			$this->releaseActiveLock($key);
			
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
			$id = $this->prefix($key, $this->getType());
		}
		
		$this->connection->debug('release lock: ' . $id, true);
		
		if(array_key_exists($id, $this->queueLocks) === false)
		{
			return false;
		}
		
		$lockValue = $this->queueLocks[$id];
		unset($this->queueLocks[$id]);
		
		$lockKey = $this->prefix(self::TYPE_LOCK, $id);
		$channelName = $this->prefix(self::TYPE_CHANNEL, $id);
		
		try
		{
			// atomically release the lock and notify any waiters using the Lua script
			return (bool)$this->functionCall('store_release_lock_and_publish',
				[$lockKey, $channelName],
				[$lockValue],
			);
		}
		finally
		{
			$this->connection->debug('lock released: ' . $id);
		}
	}
	
	/**
	 * This function should be used for long-running processes
	 * which hold the lock for longer than default lock TTL
	 */
	public function renewLock(
		string $key,
		?int $ttlMs = null,
	): bool
	{
		$id = $this->prefix($key, $this->getType());
		
		if(array_key_exists($id, $this->queueLocks) === false)
		{
			return false;
		}
		
		$lockKey = $this->prefix(self::TYPE_LOCK, $id);
		$lockValue = $this->queueLocks[$id];
		$ttlMs = $ttlMs ?? $this->queueLockTtlMs;
		
		return (bool)$this->functionCall('store_renew_lock',
			[$lockKey],
			[$lockValue, $ttlMs],
		);
	}
	
	/**
	 * Throws exception on purpose, this method is not meant to be used by normal users
	 */
	public function clear(): bool|int
	{
		if(($client = $this->getClient()) === null)
		{
			return false;
		}
		
		$group = $this->getGroup();
		$prefix = $this->prefix('*', $group);
		$count = 0;
		
		$client->clearLastError();
		
		$result = $this->functionCall('store_clear', [], [
			$prefix,
		], long: true);
		
		if(is_int($result))
		{
			$count = $result;
		}
		
		if($error = $client->getLastError())
		{
			throw new RedisException($error);
		}
		
		return $count;
	}
	
	/**
	 * Logs events (messages/errors/exceptions)
	 */
	public function log(
		...$event,
	): static
	{
		$this->connection->log(...$event);
		
		return $this;
	}
}
