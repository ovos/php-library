<?php
declare(strict_types=1);

namespace Ovos\Connection;

use Ovos\ArrayObject;
use Override;
use Redis as RedisClient;
use RedisCluster as RedisClusterClient;
use RedisClusterException;

use function implode;
use function sprintf;

/**
 * RedisCluster
 *
 * Connects to a Redis Cluster from a list of seed nodes.
 * The client routes every single-key command to the node owning
 * the key's hash slot; commands touching multiple keys are only
 * allowed when all the keys hash to the same slot.
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class RedisCluster extends RedisCommon
{
	/**
	 * RedisCluster object
	 */
	protected ?RedisClusterClient $client = null;
	
	#[Override]
	public function getClient(): ?RedisClusterClient
	{
		return parent::getClient();
	}
	
	/**
	 * A cluster has no selectable database,
	 * identify the connection by its seeds instead
	 */
	#[Override]
	public static function getId(
		ArrayObject $config,
	): string
	{
		return implode(',', $config->getArray('seeds'));
	}
	
	public function connect(): bool
	{
		$seeds = $this->config->getArray('seeds');
		
		try
		{
			$this->client = new RedisClusterClient(
				null,
				$seeds,
				$this->connectTimeout,
				$this->readTimeout,
				(bool)($this->config->persistent ?? false),
				$this->config->auth,
			);
		}
		catch(RedisClusterException $exception)
		{
			$this->client = null;
			$this->logger->log
			(
				new RedisClusterException
				(
					sprintf(
						'Could not connect to redis cluster seeds "%s".',
						implode(', ', $seeds),
					),
					0,
					$exception, // previous
				)
			);
			
			return false;
		}
		
		// the generic options are only defined on the Redis class,
		// RedisCluster::setOption() accepts the same option ids
		$options = [
			RedisClient::OPT_SERIALIZER
				=> RedisClient::SERIALIZER_NONE,
			RedisClient::OPT_REPLY_LITERAL
				=> true, // https://github.com/phpredis/phpredis/issues/1550
			// read from master nodes only, keeping the read-after-write
			// behaviour of the standalone connection
			RedisClusterClient::OPT_SLAVE_FAILOVER
				=> RedisClusterClient::FAILOVER_NONE,
		];
		
		// set options
		foreach($options as $optionName => $optionValue)
		{
			$this->client->setOption($optionName, $optionValue);
		}
		
		return true;
	}
	
	public function disconnect(): bool
	{
		return $this->client->close();
	}
	
	/**
	 * Returns the addresses ([host, port]) of all master nodes,
	 * used to run per-node commands (FUNCTION, CONFIG, SCAN)
	 */
	public function getMasters(): array
	{
		if(($client = $this->getClient()) === null)
		{
			return [];
		}
		
		return $client->_masters();
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
			// CONFIG is a per-node command on a cluster;
			// "busy-reply-threshold" is the Redis 7+ name of "lua-time-limit"
			foreach($this->getMasters() as $master)
			{
				$client->config($master, 'SET',
					'busy-reply-threshold',
					(string)($readTimeout * 1000) // ms
				);
			}
		}
		
		return true;
	}
}
