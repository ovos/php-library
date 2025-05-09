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
	 * Separators
	 */
	public const string SEPARATOR_PREFIX = ':';
	/**#@-*/
	
	/**#@+
	 * Keys
	 */
	public const string KEY_DATA = 'data';
	/**#@-*/
	
	/**#@+
	 * Statuses
	 * Used for rawCommand, which returns strings instead of boolean values when OPT_REPLY_LITERAL is enabled
	 * @see https://github.com/phpredis/phpredis/issues/1550
	 */
	public const string STATUS_OK = 'OK';
	/**#@-*/
	
	/**#@+
	 * Library
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
	 * Redis connection
	 *
	 * @var ?Connection
	 */
	protected ?Connection $_connection = null;
	
	/**
	 * @var ?string 
	 */
	protected ?string $_prefix = null;
	
	/**
	 * @var string
	 */
	protected string $_hash = 'cache';
	
	/**
	 * @var int
	 */
	protected int $_multiMode = BaseRedis::PIPELINE;
	
	/**
	 * Read timeout for light operations 
	 * 
	 * @var float
	 */
	protected float $_readTimeout = 1;
	
	/**
	 * Read timeout for heavy operations 
	 * 
	 * @var float
	 */
	protected float $_readTimeoutLong = 10;
	
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
	
	/**
	 * @param ArrayObject $config
	 */
	public function __construct(ArrayObject $config)
	{
		parent::__construct();
		
		if($config->offsetExists('prefix') === false)
		{
			throw new Exception('"cache: prefix" is a required config value.');
		}
		
		$this->setPrefix($config->prefix);
		$this->setConfig($config->persistent);
		
		// override default values with values from config
		if($readTimeout = $this->_config->offsetGet('read_timeout'))
		{
			$this->_readTimeout = (float)$readTimeout;
		}
		
		if($readTimeoutLong = $this->_config->offsetGet('read_timeout_long'))
		{
			$this->_readTimeoutLong = (float)$readTimeoutLong;
		}
		
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
	 * @param ?string $prefix
	 *
	 * @return self
	 */
	public function setPrefix(?string $prefix = null): self
	{
		$this->_prefix = $prefix;
		
		return $this;
	}
	
	/**
	 * @param string $key
	 * @param ?string $prefix
	 *
	 * @return string
	 */
	public function prefix(string $key, ?string $prefix = null): string
	{
		return ($prefix ?: $this->_prefix) . self::SEPARATOR_PREFIX . $key;
	}
	
	/**
	 * @return bool
	 */
	public function connect(): bool
	{
		$this->_connection = new Connection($this->_config);
		return $this->_connection->connect();
	}
	
	/**
	 * @return ?BaseRedis
	 */
	public function getClient(): ?BaseRedis
	{
		return $this->_connection->getClient();
	}
	
	/**
	 * @return string
	 */
	public function getHashName(): string
	{
		return $this->prefix($this->_hash);
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
	 *
	 * @return mixed
	 */
	protected function _functionCall(
		string $function,
		array $keys = [],
		array $args = [],
		bool $readOnly = false,
	): mixed
	{
		if(($client = $this->getClient()) === null)
		{
			return false;
		}
		
		$this->loadLibraries();
		
		if($readOnly)
		{
			return $this->_slowLog([$client, 'fcall_ro'], $function, $keys, $args);
		}
		
		return $this->_slowLog([$client, 'fcall'], $function, $keys, $args);
	}
	
	/**
	 * @param string $function
	 * @param array $keys
	 * @param array $args
	 * @param bool $readOnly
	 * @param int $batchSize
	 *
	 * @return void
	 */
	protected function _batchFunctionCall(
		string $function,
		array $keys = [],
		array $args = [],
		bool $readOnly = false,
		int $batchSize = 1000,
	): void
	{
		$countKeys = count($keys);
		$totalBatches = (int)ceil($countKeys / $batchSize);
		
		for($batch = 0; $batch < $totalBatches; $batch++)
		{
			$keysBatch = array_slice($keys, $batch * $batchSize, $batchSize);
			$this->_functionCall($function, $keysBatch, $args, $readOnly);
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
			$this->prefix($key, $this->getHashName()),
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
			$this->prefix($key, $this->getHashName()),
		) > 0;
	}
	
	/**
	 * @param string $key
	 * @param mixed $value
	 * @param int $ttl
	 *
	 * @return bool
	 */
	public function set(string $key, mixed $value, int $ttl = 0): bool
	{
		if(($client = $this->getClient()) === null)
		{
			return false;
		}
		
		$value = $this->compress($this->serialize($value));
		
		$client->multi($this->_multiMode);
		$client->hSet(
			$this->prefix($key, $this->getHashName()), 
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
		
		$hashName = $this->getHashName();
		$prefix = $this->prefix('*', $hashName);
		$count = 0;
		
		$client->clearLastError();
		$client->setOption(BaseRedis::OPT_READ_TIMEOUT, $this->_readTimeoutLong);
		$client->config('SET', 
			'lua-time-limit',
			(string)($this->_readTimeoutLong * 1000) // ms
		);
		
		$result = $this->_functionCall('cache_clear', [], [
			$prefix,
		]);
		
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
