<?php
declare(strict_types=1);

namespace Ovos\Store;

use Ovos\Exception;
use Ovos\ArrayObject;
use Ovos\Redis\Connection;
use Redis as BaseRedis;
use RedisException;

use function Ovos\services;
use function is_int;

/**
 * Redis
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
class Redis extends Cache
{
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
	public const string TYPE_TAGS = 'tags';
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
	
	/**
	 * Slow log - logs slow Redis queries into file
	 * 
	 * @var bool
	 */
	protected bool $_slowLogEnabled = false;
	
	/**
	 * Log queries slower than x seconds
	 * 
	 * @var float
	 */
	protected float $_slowLogThreshold = 2.0;
	
	/**
	 * Log queries into a file with this filename
	 * 
	 * @var string
	 */
	protected string $_slowLogFilename = 'redis_slow';
	
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
	
	/**
	 * @param string $prefix
	 * @param Connection $connection
	 * @param ArrayObject $config
	 * @param ?string $group
	 */
	public function __construct
	(
		string $prefix,
		Connection $connection,
		ArrayObject $config,
		?string $group = null,
	)
	{
		parent::__construct();
		
		$this->setPrefix($prefix);
		$this->setConnection($connection);
		$this->setConfig($config);
		$this->setGroup($group);
		
		$this->_initSlowLog();
	}
	
	/**
	 * @return void
	 */
	protected function _initSlowLog(): void
	{
		if(($slowLog = $this->_config->offsetGet('slow_log')) === null)
		{
			return;
		}
		
		if($slowLogEnabled = $slowLog->offsetGet('enabled'))
		{
			$this->_slowLogEnabled = $slowLogEnabled;
		}
		if($slowLogThreshold = $slowLog->offsetGet('threshold'))
		{
			$this->_slowLogThreshold = $slowLogThreshold;
		}
		if($slowLogFilename = $slowLog->offsetGet('filename'))
		{
			$this->_slowLogFilename = $slowLogFilename;
		}
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
		
		$library = "#!lua name=" . $libraryName . PHP_EOL . PHP_EOL
			. file_get_contents(__DIR__ . DIRECTORY_SEPARATOR . $libraryFile);
		
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
		
		$call = $readOnly ? 'fcall_ro' : 'fcall';
		$result = $this->_slowLog([$client, $call], $function, $keys, $args);
		
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
	 * @param int $batchSize
	 * @param bool $long
	 * 
	 * @return void
	 */
	protected function _batchFunctionCall(
		string $function,
		array $keys = [],
		array $args = [],
		bool $readOnly = false,
		int $batchSize = 1000,
		bool $long = false,
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
	 *
	 * @return null|mixed
	 */
	public function get(string $key): mixed
	{
		if(($client = $this->getClient()) === null)
		{
			return null;
		}
		
		$value = $client->hGet(
			$this->prefix($key, $this->getType()),
			self::KEY_DATA,
		);
		if($value === false)
		{
			return null;
		}
		
		return $this->unserialize($this->decompress($value));
	}
	
	/**
	 * @param string $key
	 *
	 * @return null|bool
	 */
	public function delete(string $key): null|bool
	{
		if(($client = $this->getClient()) === null)
		{
			return null;
		}
		
		return $client->unlink(
			$this->prefix($key, $this->getType()),
		) > 0;
	}
	
	/**
	 * @param string $key
	 * @param mixed $value
	 * @param int $ttl
	 *
	 * @return bool
	 */
	public function set(
		string $key,
		mixed $value,
		int $ttl = 0,
	): bool
	{
		if(($client = $this->getClient()) === null)
		{
			return false;
		}
		
		$value = $this->compress($this->serialize($value));
		
		// @see https://redis.io/docs/latest/commands/hset/
		$client->multi($this->_multiMode);
		$client->hSet(
			$this->prefix($key, $this->getType()), 
			self::KEY_DATA, $value,
		);
		
		// set expire if needed
		if($ttl > 0)
		{
			$client->expire($key, $ttl);
		}
		$result = $client->exec();
		
		return $result[0] !== false;
	}
	
	/**
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
	 * @param callable $callback
	 * @param string $function
	 * @param array $keys
	 * @param array $args
	 *
	 * @return mixed
	 */
	protected function _slowLog(
		callable $callback,
		string $function,
		array $keys,
		array $args,
	): mixed
	{
		if($this->_slowLogEnabled)
		{
			$start = microtime(true);
		}
		
		$result = $callback($function, $keys, $args);
		
		if($this->_slowLogEnabled)
		{
			$end = microtime(true);
			$diff = $end - $start;
			
			if($diff >= $this->_slowLogThreshold)
			{
				$message = sprintf('%s: %s = %ss' . PHP_EOL,
					date('Y-m-d H:i:s'),
					$function,
					$diff
				);
				services()->logger->log($message);
				
				$filename = sprintf('%s_%s.txt',
					$this->_slowLogFilename,
					date('Y_m_d')
				);
				
				// log to a slow log file
				file_put_contents(LOGS_DIR . $filename,
					$message
				, FILE_APPEND);
				
				if(count($keys))
				{
					file_put_contents(LOGS_DIR . $filename,
						'keys: ' . "\n\t" . implode("\n\t", $keys) . PHP_EOL
					, FILE_APPEND);
				}
				if(count($args))
				{
					file_put_contents(LOGS_DIR . $filename,
						'args: ' . "\n\t" . implode("\n\t", $args) . PHP_EOL
					, FILE_APPEND);
				}
				file_put_contents(LOGS_DIR . $filename, 
				PHP_EOL
				, FILE_APPEND);
			}
		}
		
		return $result;
	}
}
