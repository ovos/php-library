<?php
declare(strict_types=1);

namespace Ovos\Connection;

use Override;
use Ovos\Redis\Profiler\Client as ProfilerClient;
use Ovos\Redis\Profiler\Collector;
use Redis as RedisClient;
use RedisException;

use function sprintf;

/**
 * Redis
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Redis extends RedisCommon
{
	// Connection options
	/**
	 * Return raw replies instead of converting status strings like
	 * "+OK" into booleans (required by rawCommand)
	 * @see https://github.com/phpredis/phpredis/issues/1550
	 */
	protected const bool REPLY_LITERAL = true;
	
	/**
	 * Do not cap the automatic retries - let the timeout bound them
	 */
	protected const int MAX_RETRIES = 0;
	
	/**
	 * The minimum delay between retries when backing off
	 * @see https://github.com/phpredis/phpredis/pull/1993/files
	 * Unit: milliseconds
	 */
	protected const int BACKOFF_BASE = 500;
	
	/**
	 * The maximum delay between retries when backing off
	 * Unit: milliseconds
	 */
	protected const int BACKOFF_CAP = 750;
	
	/**
	 * Redis object
	 */
	protected ?RedisClient $client = null;
	
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
		// dev profiling: the profiling subclass records raw-client commands into
		// the redis profiler; production uses the plain client (zero overhead)
		if($this->profilers?->enabled === true)
		{
			Collector::$limit = (int)($this->profilers->redis?->limit ?? 0);
			$this->client = new ProfilerClient($connectionOptions);
		}
		else
		{
			$this->client = new RedisClient($connectionOptions);
		}
		
		$options = [
			RedisClient::OPT_READ_TIMEOUT
				=> $this->readTimeout,
			RedisClient::OPT_SERIALIZER
				=> RedisClient::SERIALIZER_NONE,
			RedisClient::OPT_REPLY_LITERAL
				=> self::REPLY_LITERAL,
			RedisClient::OPT_MAX_RETRIES
				=> self::MAX_RETRIES,
			RedisClient::OPT_BACKOFF_ALGORITHM
				=> RedisClient::BACKOFF_ALGORITHM_DECORRELATED_JITTER, // https://github.com/phpredis/phpredis/pull/1993/files
			RedisClient::OPT_BACKOFF_BASE
				=> self::BACKOFF_BASE,
			RedisClient::OPT_BACKOFF_CAP
				=> self::BACKOFF_CAP,
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
					sprintf(
						'Could not connect to redis server "%s" on port "%s".',
						$this->config->host,
						$this->config->port,
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
		if(($client = $this->getClient()) === null)
		{
			return false;
		}
		
		return $client->close();
	}
	
	#[Override]
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
			// the Redis 7+ name of "lua-time-limit"
			$client->config('SET',
				'busy-reply-threshold',
				(string)($readTimeout * 1000) // ms
			);
		}
		
		return true;
	}
}
