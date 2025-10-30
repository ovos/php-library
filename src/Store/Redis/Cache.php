<?php
declare(strict_types=1);

namespace Ovos\Store\Redis;

use Ovos\ArrayObject;
use Ovos\Exception;
use Ovos\Redis\Connection;
use Ovos\Store\Cache\Tags;
use Closure;
use Throwable;
use Redis as BaseRedis;
use RedisException;

use function array_slice;
use function array_key_exists;
use function bin2hex;
use function ceil;
use function count;
use function file_get_contents;
use function is_int;
use function random_bytes;
use function str_replace;

/**
 * Cache
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
abstract class Cache extends Tags
{
	/**#@+
	 * Separators
	 */
	public const string SEPARATOR_FUNCTION = '_';
	/**#@-*/
	
	/**
	 * @var ?string 
	 */
	protected ?string $_functionPrefix = null;
	
	/**#@+
	 * Keys
	 */
	public const string KEY_DATA = 'data';
	public const string KEY_TAGS = 'tags';
	/**#@-*/
	
	/**#@+
	 * Statuses
	 * Used for rawCommand, which returns strings instead of boolean values when OPT_REPLY_LITERAL is enabled
	 * @see https://github.com/phpredis/phpredis/issues/1550
	 */
	public const string STATUS_OK = 'OK';
	/**#@-*/
	
	/**
	 * Types
	 */
	public const string TYPE_ITEMS = 'items';
	public const string TYPE_LOCK = 'lock';
	public const string TYPE_CHANNEL = 'channel';
	/**#@-*/
	
	/**
	 * Redis connection
	 *
	 * @var Connection
	 */
	protected Connection $_connection;
	
	/**
	 * @var int
	 */
	protected int $_multiMode = BaseRedis::PIPELINE;
	
	/**#@+
	 * Libraries
	 */
	/**
	 * The array of function libraries used by this lass
	 */
	public const array LIBRARIES = [
		'cache' => 'Lua' . DIRECTORY_SEPARATOR . 'Cache.lua',
	];
	/**#@-*/
	
	/**
	 * @var array
	 */
	protected array $_librariesLoaded = [];
	
	/**#@+
	 * Queue (MemoLock) configuration
	 */
	
	/**
	 * @var bool
	 */ 
	protected bool $_queueEnabled = false;
	
	/**
	 * @var int
	 */
	protected int $_queueLockTtlMs = 2000;
	
	/**
	 * @var int
	 */
	protected int $_queueWaitAttempts = 3;
	
	/**#@-*/
	
	/**
	 * An array of unique values for any active locks,
	 * indexed by the prefixed cache id
	 * 
	 * @var array
	 */
	protected array $_queueLocks = [];
	
	/**
	 * @param Connection $connection
	 * @param ArrayObject $config
	 * @param ?string $prefix
	 * @param ?string $group
	 */
	public function __construct
	(
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
		
		if($storeOptions = $this->_config->offsetGet('store_options'))
		{
			$this->setStoreOptions($storeOptions);
		}
		
		if($queue = $this->_config->offsetGet('queue'))
		{
			$this->setQueue($queue);
		}
	}
	
	/**
	 * @param ?string $prefix
	 *
	 * @return self
	 */
	public function setPrefix(?string $prefix = null): self
	{
		$this->_prefix = $prefix;
		
		if($prefix !== null)
		{
			$this->_functionPrefix = str_replace
			(
				[self::SEPARATOR_PREFIX, '-'],
				[self::SEPARATOR_FUNCTION, self::SEPARATOR_FUNCTION],
				$prefix,
			);
		}
		
		return $this;
	}
	
	/**
	 * @param Connection $connection
	 *
	 * @return self
	 */
	public function setConnection(Connection $connection): self
	{
		$this->_connection = $connection;
		
		return $this;
	}
	
	/**
	 * @return Connection
	 */
	public function getConnection(): Connection
	{
		return $this->_connection;
	}
	
	/**
	 * @param ArrayObject $options
	 *
	 * @return self
	 */
	public function setStoreOptions(ArrayObject $options): self
	{
		return $this;
	}
	
	/**
	 * @param ArrayObject $config
	 *
	 * @return self
	 */
	public function setQueue(ArrayObject $config): self
	{
		if(($enabled = $config->offsetGet('enabled')) !== null) // true or false
		{
			$this->_queueEnabled = $enabled;
		}
		if(($lockTtlMs = $config->offsetGet('lock_ttl_ms')) !== null)
		{
			$this->_queueLockTtlMs = $lockTtlMs;
		}
		if(($waitAttempts = $config->offsetGet('wait_attempts')) !== null)
		{
			$this->_queueWaitAttempts = $waitAttempts;
		}
		
		return $this;
	}
	
	/**
	 * @param bool $enabled
	 *
	 * @return self
	 */
	public function setQueueEnabled(bool $enabled): self
	{
		$this->_queueEnabled = $enabled;
		
		return $this;
	}
	
	/**
	 * @return bool
	 */
	public function isQueueEnabled(): bool
	{
		return $this->_queueEnabled;
	}
	
	/**
	 * @param string $key
	 * @param ?string $prefix
	 * @param string $separator
	 *
	 * @return string
	 */
	public function functionPrefix(string $key,
		?string $prefix = null,
		string $separator = self::SEPARATOR_FUNCTION
	): string
	{
		$prefix = $prefix ?? $this->_functionPrefix;
		
		return $prefix !== null
			? $prefix . $separator . $key
			: $key;
	}
	
	/**
	 * @param Connection $connection
	 * @param ArrayObject $config
	 * @param ?string $group
	 *
	 * @return self
	 */
	public static function fromConfig(Connection $connection,
		ArrayObject $config,
		?string $group = null,
	): self
	{
		return new static
		(
			$connection,
			$config->persistent,
			$config->prefix,
			$group ?? self::GROUP_DEFAULT,
		);
	}
	
	/**
	 * @return ?BaseRedis
	 */
	public function getClient(): ?BaseRedis
	{
		return $this->_connection->getClient();
	}
	
	/**
	 * @param string $type
	 *
	 * @return string
	 */
	public function getType(string $type = self::TYPE_ITEMS): string
	{
		return $this->prefix($type, $this->getGroup());
	}
	
	/**
	 * Ensures that all the libraries of scripts are loaded into redis
	 * 
	 * @param bool $replace
	 *
	 * @return bool
	 */
	public function loadLibraries(bool $replace = false): bool
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
	 * 
	 * @param string $libraryName
	 * @param string $libraryFile
	 * @param bool $replace
	 * 
	 * @return bool
	 */
	public function loadLibrary
	(
		string $libraryName,
		string $libraryFile,
		bool $replace = false
	): bool
	{
		$libraryName = $this->functionPrefix($libraryName);
		
		if(isset($this->_librariesLoaded[$libraryName])
			&& $this->_librariesLoaded[$libraryName] === true
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
				$this->_librariesLoaded[$libraryName] = true;
				
				return true;
			}
		}
		
		$client->clearLastError();
		
		$functions = file_get_contents(__DIR__
			. DIRECTORY_SEPARATOR . $libraryFile,
		);
		
		$functions = str_replace('[prefix]',
			$this->_functionPrefix
				? $this->_functionPrefix . self::SEPARATOR_FUNCTION
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
			$this->_librariesLoaded[$libraryName] = true;
			
			return true;
		}
		
		return false;
	}
	
	/**
	 * @param string $function
	 * @param array $keys
	 * @param array $args
	 * @param bool $readOnly
	 * @param bool $long
	 *
	 * @return mixed
	 */
	protected function _functionCall(
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
			$this->_connection->toggleReadTimeout(Connection::TIMEOUT_READ_LONG);
		}
		
		$call = $readOnly
			? 'fcall_ro'
			: 'fcall'
		;
		$functionName = $this->functionPrefix($function);
		
		$result = $this->_connection->slowLog([$client, $call], $functionName, $keys, $args);
		
		if($long)
		{
			$this->_connection->toggleReadTimeout();
		}
		
		return $result;
	}
	
	/**
	 * @param string $function
	 * @param array $keys
	 * @param array $args
	 * @param bool $readOnly
	 * @param bool $long
	 * @param int $batchSize
	 * 
	 * @return void
	 */
	protected function _batchFunctionCall(
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
			$this->_connection->toggleReadTimeout(Connection::TIMEOUT_READ_LONG);
		}
		
		$countKeys = count($keys);
		$totalBatches = (int)ceil($countKeys / $batchSize);
		
		for($batch = 0; $batch < $totalBatches; $batch++)
		{
			$keysBatch = array_slice($keys, $batch * $batchSize, $batchSize);
			$this->_functionCall($function, $keysBatch, $args, $readOnly);
		}
		
		if($long)
		{
			$this->_connection->toggleReadTimeout();
		}
	}
	
	/**
	 * @param string $key
	 * @param ?Closure $setCallback
	 * @param int $ttl
	 * @param array $tags
	 * @param bool $queue override for the config switch
	 * @param ?int $queueLockTtlMs override for the config value
	 *
	 * @return null|mixed
	 */
	public function get(
		string $key,
		?Closure $setCallback = null,
		int $ttl = 0,
		array $tags = [],
		?bool $queue = null,
		?int $queueLockTtlMs = null,
	): mixed
	{
		if(($client = $this->getClient()) === null)
		{
			return $this->callSetCallback($setCallback);
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
			
			return $this->callSetCallback($setCallback);
		}
		
		// cache miss, queue logic begins
		return $this->_queue(
			$client,
			$key,
			$id,
			$setCallback,
			$ttl,
			$tags,
			$queue,
			$queueLockTtlMs,
		);
	}
	
	/**
	 * @param string $key
	 * @param ?Closure $setCallback
	 * @param int $ttl
	 * @param array $tags
	 * @param ?int $queueLockTtlMs override for the config value
	 * @param bool $lockOnly
	 *
	 * @return mixed
	 */
	public function queue(
		string $key,
		?Closure $setCallback = null,
		int $ttl = 0,
		array $tags = [],
		?int $queueLockTtlMs = null,
		bool $lockOnly = false,
	): mixed
	{
		if(($client = $this->getClient()) === null)
		{
			return $this->callSetCallback($setCallback);
		}
		
		$id = $this->prefix($key, $this->getType());
		
		return $this->_queue(
			$client,
			$key,
			$id,
			$setCallback,
			$ttl,
			$tags,
			true,
			$queueLockTtlMs,
			$lockOnly,
		);
	}
	
	/**
	 * @param BaseRedis $client
	 * @param string $key
	 * @param string $id
	 * @param ?Closure $setCallback
	 * @param int $ttl
	 * @param array $tags
	 * @param bool $queue override for the config switch
	 * @param ?int $queueLockTtlMs override for the config value
	 * @param bool $lockOnly
	 * 
	 * @return mixed
	 */
	protected function _queue(
		BaseRedis $client,
		string $key,
		string $id,
		?Closure $setCallback = null,
		int $ttl = 0,
		array $tags = [],
		?bool $queue = null,
		?int $queueLockTtlMs = null,
		bool $lockOnly = false,
	): mixed
	{
		if(($this->_queueEnabled === false && $queue !== true)
			|| ($this->_queueEnabled === true && $queue === false)
		)
		{
			return $this->setFromCallback($key, $setCallback, $ttl, $tags);
		}
		
		$lockKey = $this->prefix(self::TYPE_LOCK, $id);
		$channelName = $this->prefix(self::TYPE_CHANNEL, $id);
		$lockValue = bin2hex(random_bytes(16));
		$queueLockTtlMs = $queueLockTtlMs ?? $this->_queueLockTtlMs;
		
		// try to acquire a distributed lock
		$lockAcquired = $client->set($lockKey, $lockValue, [
			'NX',
			'PX' => $queueLockTtlMs,
		]);
		
		if($lockAcquired)
		{
			// lock acquired, store it internally for releaseActiveLock()
			return $this->_lockAcquired($key,
				$id,
				$lockValue,
				$setCallback,
				$ttl,
				$tags,
			);
		}
		
		// lock not acquired
		$waitTimeMs = $waitTimeJitterMs = $queueLockTtlMs;
		for($attempt = 0; $attempt < $this->_queueWaitAttempts; $attempt++)
		{
			// set read timeout to the requested queue lock TTL
			$this->_connection->toggleReadTimeout(
				Connection::TIMEOUT_READ_CUSTOM, 
				$waitTimeJitterMs / 1000, // milliseconds to seconds
				false,
			);
			
			try
			{
				// block and wait for a message on the channel or a timeout (when no message is received)
				$client->subscribe([$channelName],
					function($client, $channelName, $message)
					{
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
				$waitTimeJitterMs = $waitTimeMs + random_int(0, (int)($waitTimeMs * 0.5));
			}
			finally
			{
				// restore the default read timeout
				$this->_connection->toggleReadTimeout();
			}
			
			// either we got the message or we timed-out
			try
			{
				if($lockOnly === false)
				{
					// check if data is already there
					$value = $client->hGet(
						$id,
						self::KEY_DATA,
					);
					if($value !== false)
					{
						return $this->unserialize($this->decompress($value));
					}
				}
				
				// check if the lock still exists
				if($client->exists($lockKey) === false)
				{
					// try to acquire a distributed lock
					$lockAcquired = $client->set($lockKey, $lockValue, [
						'NX',
						'PX' => $queueLockTtlMs,
					]);
					
					if($lockAcquired)
					{
						// lock acquired, store it internally for releaseActiveLock()
						return $this->_lockAcquired($key,
							$id,
							$lockValue,
							$setCallback,
							$ttl,
							$tags,
						);
					}
				}
			}
			catch(RedisException $exception)
			{
				$this->log($exception);
			}
			// if the lock still exists, or we failed to acquire it, loop to wait again
		}
		
		// we tried, time to fetch the data ourselves
		return $this->callSetCallback($setCallback);
	}
	
	/**
	 * @param string $key
	 * @param string $id
	 * @param string $lockValue
	 * @param ?Closure $setCallback
	 * @param int $ttl
	 * @param array $tags
	 *
	 * @return mixed
	 */
	protected function _lockAcquired(
		string $key,
		string $id,
		string $lockValue,
		?Closure $setCallback = null,
		int $ttl = 0,
		array $tags = [],
	): mixed
	{
		$this->_queueLocks[$id] = $lockValue;
		
		try
		{
			return $this->setFromCallback($key, $setCallback, $ttl, $tags);
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
	 * 
	 * @param string $key
	 * @param ?string $id
	 *
	 * @return bool
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
		
		if(array_key_exists($id, $this->_queueLocks) === false)
		{
			return false;
		}
		
		$lockValue = $this->_queueLocks[$id];
		unset($this->_queueLocks[$id]);
		
		$lockKey = $this->prefix(self::TYPE_LOCK, $id);
		$channelName = $this->prefix(self::TYPE_CHANNEL, $id);
		
		// atomically release the lock and notify any waiters using the Lua script
		return (bool)$this->_functionCall('cache_release_lock_and_publish',
			[$lockKey, $channelName],
			[$lockValue],
		);
	}
	
	/**
	 * This function should be used for long-running processes
	 * which hold the lock for longer than default lock TTL
	 * 
	 * @param string $key
	 * @param ?int $ttlMs
	 *
	 * @return bool
	 */
	public function renewLock(string $key, ?int $ttlMs = null): bool
	{
		$id = $this->prefix($key, $this->getType());
		
		if(array_key_exists($id, $this->_queueLocks) === false)
		{
			return false;
		}
		
		$lockKey = $this->prefix(self::TYPE_LOCK, $id);
		$lockValue = $this->_queueLocks[$id];
		$ttlMs = $ttlMs ?? $this->_queueLockTtlMs;
		
		return (bool)$this->_functionCall('cache_renew_lock',
			[$lockKey],
			[$lockValue, $ttlMs],
		);
	}
	
	/**
	 * Throws exception on purpose, this method is not meant to be used by normal users
	 * 
	 * @return bool|int
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
		
		$result = $this->_functionCall('cache_clear', [], [
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
	 *
	 * @param mixed ...$event
	 *
	 * @return self
	 */
	public function log(...$event): self
	{
		$this->_connection->log(...$event);
		
		return $this;
	}
}
