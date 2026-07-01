<?php
declare(strict_types=1);

namespace Ovos\Connection;

use Ovos\ArrayObject;
use Ovos\Connection;

use function count;
use function date;
use function file_put_contents;
use function implode;
use function microtime;
use function sprintf;

/**
 * RedisCommon
 *
 * Shared configuration (timeouts, slow log) of the Redis protocol connections,
 * extended by the standalone Redis and the RedisCluster connections.
 *
 * @author Marcin Gil <mg@ovos.at>
 */
abstract class RedisCommon extends Connection
{
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
	
	public function __construct(ArrayObject $config)
	{
		parent::__construct($config);
		
		$this->configure($config);
	}
	
	public function configure(ArrayObject $config): static
	{
		// initialize timeout values taking in consideration default values set in this class
		$this->connectTimeout = (float)
		(
			$config->connect_timeout
			?? $this->connectTimeout
		);
		
		$this->readTimeout = (float)
		(
			$config->read_timeout
			?? $this->readTimeout
		);
		
		$this->readTimeoutLong = (float)
		(
			$config->read_timeout_long
			?? $this->readTimeoutLong
		);
		
		if($slowLog = $config->offsetGet('slow_log'))
		{
			$this->setSlowLog($slowLog);
		}
		
		return $this;
	}
	
	public function setSlowLog(
		ArrayObject $config,
	): static
	{
		if($slowLogEnabled = $config->offsetGet('enabled'))
		{
			$this->slowLogEnabled = $slowLogEnabled;
		}
		if($slowLogThreshold = $config->offsetGet('threshold'))
		{
			$this->slowLogThreshold = $slowLogThreshold;
		}
		if($slowLogFilename = $config->offsetGet('filename'))
		{
			$this->slowLogFilename = $slowLogFilename;
		}
		
		return $this;
	}
	
	/**
	 * Can be used to extend and restore timeout to the original value
	 */
	abstract public function toggleReadTimeout(
		string $timeout = self::TIMEOUT_READ,
		?float $timeoutValue = null,
		bool $luaScript = true,
	): bool;
	
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
						'keys: ' . "\n\t"
							. implode("\n\t", $keys) . PHP_EOL
					, FILE_APPEND);
				}
				if(count($args))
				{
					file_put_contents(LOGS_DIR . $filename,
						'args: ' . "\n\t"
							. implode("\n\t", $args) . PHP_EOL
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
