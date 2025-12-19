<?php
declare(strict_types=1);

namespace Ovos\Connection;

use Ovos\ArrayObject;
use Ovos\Client;
use Ovos\Connection;
use Override;
use Redis as RedisClient;
use RedisException;

use function count;
use function date;
use function file_put_contents;
use function microtime;
use function sprintf;

/**
 * Redis
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
class Redis extends Connection
{
	/**
	 * Redis object
	 */
	protected ?RedisClient $client = null;
	
	// Timeouts
	public const string TIMEOUT_READ = 'read';
	public const string TIMEOUT_READ_LONG = 'long';
	public const string TIMEOUT_READ_CUSTOM = 'custom';
	
	/**
	 * Connect timeout
	 * Unit: seconds
	 */
	protected float $connectTimeout = 1;
	
	/**
	 * Read timeout for light operations
	 * Unit: seconds
	 */
	protected float $readTimeout = 1;
	
	/**
	 * Read timeout for long operations
	 * Unit: seconds
	 */
	protected float $readTimeoutLong = 10;
	
	/**
	 * Slow log - logs slow Redis queries into file
	 */
	protected bool $slowLogEnabled = false;
	
	/**
	 * Log queries slower than x seconds
	 */
	protected float $slowLogThreshold = 2.0;
	
	/**
	 * Log queries into a file with this filename
	 */
	protected string $slowLogFilename = 'redis_slow';
	
	/**
	 * Debug
	 */
	protected string $debugFilename = 'redis_debug';
	protected bool $debug = false;
	protected bool $debugTimeouts = false;
	
	public function __construct(ArrayObject $config)
	{
		parent::__construct($config);
		
		// initialize timeout values taking in consideration default values set in this class
		$this->connectTimeout = (float)
		(
			$this->config->connect_timeout
			?? $this->config->timeout
			?? $this->connectTimeout
		);
		
		$this->readTimeout = (float)
		(
			$this->config->read_timeout
			?? $this->readTimeout
		);
		
		$this->readTimeoutLong = (float)
		(
			$this->config->read_timeout_long
			?? $this->readTimeoutLong
		);
		
		$this->initSlowLog();
	}
	
	#[Override]
	public function getClient(): ?RedisClient
	{
		return parent::getClient();
	}
	
	public function connect(): bool
	{
		$port = (int)($this->config->port ?? 6379);
		
		$connectionOptions = [
			'host' => $this->config->host,
			'port' => $port,
			'connectTimeout' => $this->connectTimeout,
		];
		$this->client = new RedisClient($connectionOptions);
		
		$options = [
			RedisClient::OPT_READ_TIMEOUT => $this->readTimeout,
			RedisClient::OPT_SERIALIZER => RedisClient::SERIALIZER_NONE,
			RedisClient::OPT_REPLY_LITERAL => true, // https://github.com/phpredis/phpredis/issues/1550
			RedisClient::OPT_MAX_RETRIES => 0, // do not limit the max retries, let the timeout handle it
			RedisClient::OPT_BACKOFF_ALGORITHM => RedisClient::BACKOFF_ALGORITHM_DECORRELATED_JITTER, // https://github.com/phpredis/phpredis/pull/1993/files
			RedisClient::OPT_BACKOFF_BASE => 500, // the minimum delay between retries when backing off
			RedisClient::OPT_BACKOFF_CAP => 750, // the maximum delay between replies when backing off
		];
		
		// set options
		foreach($options as $optionName => $optionValue)
		{
			$this->client->setOption($optionName, $optionValue);
		}
		
		try
		{
			$this->client->select($this->config->database);
		}
		catch(RedisException $exception)
		{
			$this->client = null;
			$this->logger->log
			(
				new RedisException
				(
					sprintf('Could not connect to redis server "%s" on port "%s".',
						$this->config->host,
						$this->config->port
					), 
					0,
					$exception, // previous
				)
			);
			
			return false;
		}
		
		return true;
	}
	
	public function disconnect(): bool
	{
		return $this->client->close();
	}
	
	protected function initSlowLog(): void
	{
		if(($slowLog = $this->config->offsetGet('slow_log')) === null)
		{
			return;
		}
		
		if($slowLogEnabled = $slowLog->offsetGet('enabled'))
		{
			$this->slowLogEnabled = $slowLogEnabled;
		}
		if($slowLogThreshold = $slowLog->offsetGet('threshold'))
		{
			$this->slowLogThreshold = $slowLogThreshold;
		}
		if($slowLogFilename = $slowLog->offsetGet('filename'))
		{
			$this->slowLogFilename = $slowLogFilename;
		}
	}
	
	public function slowLog(
		callable $callback,
		string $function,
		array $keys,
		array $args,
	): mixed
	{
		if($this->slowLogEnabled)
		{
			$start = microtime(true);
		}
		
		$result = $callback($function, $keys, $args);
		
		if($this->slowLogEnabled)
		{
			$end = microtime(true);
			$diff = $end - $start;
			
			if($diff >= $this->slowLogThreshold)
			{
				$message = sprintf('%s: %s = %ss' . PHP_EOL,
					date('Y-m-d H:i:s'),
					$function,
					$diff
				);
				$this->logger->log($message);
				
				$filename = sprintf('%s_%s.txt',
					$this->slowLogFilename,
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
	
	public function debug(
		string $message,
		bool $trace = false,
		bool $timeout = false,
	): void
	{
		if($this->debug === false
			&& ($this->debugTimeouts === true
				&& $timeout === true) === false
		)
		{
			return;
		}
		
		$filename = sprintf('%s_%s.txt',
			$this->debugFilename,
			date('Y_m_d'),
		);
		
		static $requestId = null;
		if($requestId === null)
		{
			$requestId = substr(bin2hex(
				random_bytes(4)
			), 0, 8);
		}
		
		$message = sprintf(
			'%s [%s] %s: %s' . PHP_EOL,
			Client::getIp(),
			$requestId,
			(new DateTime())->format('Y-m-d H:i:s.u'),
			$message,
		);
		
		if($trace)
		{
			$backtrace = new Exception;
			$message.= $backtrace->getTraceAsString() . PHP_EOL;
		}
		
		file_put_contents(LOGS_DIR . $filename,
			$message
		, FILE_APPEND);
	}
	
	/**
	 * Can be used to extend and restore timeout to the original value
	 */
	public function toggleReadTimeout(
		string $timeout = self::TIMEOUT_READ,
		?float $timeoutValue = null,
		bool $luaScript = true,
	): bool
	{
		if(($client = $this->getClient()) === null)
		{
			return false;
		}
		
		$readTimeout = match($timeout)
		{
			self::TIMEOUT_READ_LONG => $this->readTimeoutLong,
			self::TIMEOUT_READ_CUSTOM => $timeoutValue,
			default => $this->readTimeout,
		};
		
		$client->setOption(RedisClient::OPT_READ_TIMEOUT, $readTimeout);
		
		if($luaScript)
		{
			$client->config('SET', 
				'lua-time-limit',
				(string)($readTimeout * 1000) // ms
			);
		}
		
		return true;
	}
}
