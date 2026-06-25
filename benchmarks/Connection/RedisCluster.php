<?php
declare(strict_types=1);

namespace Benchmarks\Connection;

use Ovos\Benchmark;
use Ovos\Connection\Redis as RedisConnection;
use Ovos\Connection\RedisCluster as RedisClusterConnection;
use Ovos\Connection\RedisCommon as Connection;
use Ovos\Connections;
use Ovos\Exception\MissingException\MissingConfigException;
use Ovos\Test\Internal;
use Override;

/**
 * RedisCluster
 *
 * Measures the per-command overhead of the cluster client against the
 * standalone client on identical single-key operations (the cluster
 * client hashes every key to its slot and routes to the owning node).
 *
 * Requires a "redis_cluster" connection in the environment config,
 * skipped when it is missing or either backend is not reachable.
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class RedisCluster extends Benchmark
{
	public const int CYCLES = 1000;
	
	protected ?RedisConnection $standalone = null;
	protected ?RedisClusterConnection $cluster = null;
	
	public function __construct()
	{
		$connections = $this->container
			->getClass(Connections::class);
		
		try
		{
			$connections->getConnectionConfig('redis_cluster');
		}
		catch(MissingConfigException)
		{
			$this->setDisabled(true,
				'no "redis_cluster" connection configured');
			
			return;
		}
		
		$this->standalone = $connections->get('redis');
		$this->cluster = $connections->get('redis_cluster');
		
		if($this->standalone->getClient() === null
			|| $this->cluster->getClient() === null)
		{
			$this->setDisabled(true,
				'redis or the redis cluster is not reachable');
		}
	}
	
	public function stringOpsStandalone(): void
	{
		$this->stringOps($this->standalone);
	}
	
	public function stringOpsCluster(): void
	{
		$this->stringOps($this->cluster);
	}
	
	public function hashOpsStandalone(): void
	{
		$this->hashOps($this->standalone);
	}
	
	public function hashOpsCluster(): void
	{
		$this->hashOps($this->cluster);
	}
	
	/**
	 * SET/GET/UNLINK cycles on string keys
	 */
	protected function stringOps(Connection $connection): void
	{
		$client = $connection->getClient();
		
		for($i = 1; $i <= self::CYCLES; $i++)
		{
			$key = 'benchmarks:connection:item' . $i;
			$client->set($key, 'test');
			$client->get($key);
			$client->unlink($key);
		}
	}
	
	/**
	 * HSET/HGET/UNLINK cycles on hashes (the data shape of the cache stores)
	 */
	protected function hashOps(Connection $connection): void
	{
		$client = $connection->getClient();
		
		for($i = 1; $i <= self::CYCLES; $i++)
		{
			$key = 'benchmarks:connection:hash' . $i;
			$client->hSet($key, 'data', 'test');
			$client->hGet($key, 'data');
			$client->unlink($key);
		}
	}
	
	/**
	 * Called by the runner after all test methods have been invoked
	 */
	#[Internal]
	#[Override]
	public function deconstruct(): void
	{
		if($this->standalone?->isConnected())
		{
			$this->standalone->disconnect();
		}
		if($this->cluster?->isConnected())
		{
			$this->cluster->disconnect();
		}
	}
}
