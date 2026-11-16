<?php
declare(strict_types=1);

namespace Ovos\Connection;

use Override;
use Ovos\Redis\Profiler\Client as ProfilerClient;
use Ovos\Redis\Profiler\Collector;
use Redis as RedisClient;
use RedisException;

use function is_string;
use function sprintf;
use function trim;

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
	 * How often ext-redis reconnects within one command when the socket was
	 * lost. 0 is no retry: the command fails at once, and the socket stays
	 * failed until the client is dropped (see getClient())
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
	
	/**
	 * The client, or null when the server cannot be reached. ext-redis marks a
	 * socket it lost a command on as failed and never opens it again by
	 * itself: every later command fails at once with "went away". A
	 * long-running process (a queue consumer, a daemon) that kept the client
	 * would run with the cache off until restarted, after a failover as after
	 * a restart of the server. So a client that is no longer connected is
	 * dropped, and the parent connects anew - at once, the server may be back
	 * already - and remembers a failed connect for `retry_after` seconds: a
	 * server that stays down costs one connect timeout per window, not one per
	 * call
	 */
	#[Override]
	public function getClient(): ?RedisClient
	{
		if($this->client !== null
			&& $this->client->isConnected() === false)
		{
			$this->client = null;
			$this->failedAt = null;
		}
		
		return parent::getClient();
	}
	
	public function connect(): bool
	{
		$host = $this->config->host;
		$port = (int)($this->config->port ?? 6379);
		
		// a host the configuration does not set is a server that cannot be
		// reached, said as such: phpredis refuses a null one with a ValueError
		// ("Invalid host" — not the RedisException handled below), which took
		// every request of console.ovos.at down when its .env lost the key
		// (2026-10-05), and takes an EMPTY one as a default server — the wrong
		// one, silently
		if(is_string($host) === false || trim($host) === '')
		{
			$this->log(new RedisException(sprintf(
				'Redis connection to database "%s" has no host: the configuration does not set one (a missing or misnamed .env key?).',
				static::getId($this->config),
			)));
			
			return false;
		}
		
		$connectionOptions = [
			'host' => $host,
			'port' => $port,
			'connectTimeout' => $this->connectTimeout,
		];
		// dev profiling: the profiling subclass records raw-client commands into
		// the redis profiler; production uses the plain client (zero overhead)
		if($this->profilers?->enabled === true)
		{
			// a real default is essential: the collector treats 0 as "no
			// limit", which grows unbounded in long-running profiled workers
			Collector::$limit = (int)($this->profilers->redis?->limit
				?? Collector::LIMIT_DEFAULT);
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
			// a server that vanished without closing the socket (a frozen VM,
			// a dropped NAT entry) is noticed instead of waited on forever
			RedisClient::OPT_TCP_KEEPALIVE
				=> 1,
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
			$this->log
			(
				new RedisException
				(
					sprintf(
						'Could not connect to redis server "%s" on port "%s".',
						$host,
						$port,
					),
					0,
					$exception, // previous
				)
			);
			
			return false;
		}
		
		return true;
	}
	
	/**
	 * Closes the connection and drops the client: the next getClient()
	 * connects anew
	 */
	public function disconnect(): bool
	{
		if($this->client === null)
		{
			return false;
		}
		
		$closed = $this->client->close();
		$this->client = null;
		
		return $closed;
	}
	
	#[Override]
	public function toggleReadTimeout(
		string $timeout = self::TIMEOUT_READ,
		?float $timeoutValue = null,
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
		
		return true;
	}
}
