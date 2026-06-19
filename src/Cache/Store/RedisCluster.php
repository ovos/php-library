<?php
declare(strict_types=1);

namespace Ovos\Cache\Store;

use Ovos\ArrayObject;
use Ovos\Connection\RedisCluster as ClusterConnection;
use Ovos\Connection\RedisCommon as Connection;
use Override;
use RedisClusterException;
use RedisException;

use function array_key_first;
use function count;
use function implode;
use function is_array;

/**
 * RedisCluster
 *
 * The versioned (rule based) store on a Redis Cluster. The standalone
 * store pairs the item and the rules keys inside one Lua call on both
 * its read and write paths - two keys of different slots, illegal on a
 * cluster - so this store splits each into per-key steps: writes read
 * the watermark separately and use the single-key stamped set, reads
 * fetch the item and evaluate the rules in PHP behind a short-lived
 * local rules cache. Invalidation is inherited untouched (a single-slot
 * FCALL on the rules key).
 *
 * No RediSearch module is required - the tags live in the item hash.
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class RedisCluster extends RedisVersioned
{
	/**
	 * The main connection must be a cluster connection;
	 * the queue connection stays standalone for pub/sub (see MemoLock),
	 * pointed at a node of the same cluster
	 */
	public function __construct(
		ClusterConnection $connection,
		Connection $queueConnection,
		?string $prefix = null,
		?ArrayObject $config = null,
		?string $group = null,
	)
	{
		parent::__construct($connection, $queueConnection, $prefix, $config, $group);
	}
	
	#[Override]
	public function getConnection(): ClusterConnection
	{
		return $this->connection;
	}
	
	/**
	 * cache_versioned_validate touches the item and the rules keys (two
	 * slots), fetch them separately and evaluate the rules in PHP
	 */
	#[Override]
	protected function fetch(
		string $id,
	): mixed
	{
		if(($client = $this->getClient()) === null)
		{
			return null;
		}
		
		try
		{
			$item = $client->hMGet($id, [
				static::KEY_DATA,
				static::KEY_TAGS,
				static::KEY_MARK,
			]);
			
			if(is_array($item) === false
				|| ($item[static::KEY_DATA] ?? false) === false)
			{
				return null;
			}
			
			if($this->isStale(
				(string)$item[static::KEY_TAGS],
				(string)$item[static::KEY_MARK],
			))
			{
				// lazily remove the stale item
				$client->unlink($id);
				
				return null;
			}
			
			$value = $this->compressor
				->decompress($item[static::KEY_DATA]);
			return $this->serializer
				->unserialize($value);
		}
		catch(RedisException|RedisClusterException $exception)
		{
			$this->log($exception);
		}
		
		return null;
	}
	
	/**
	 * cache_versioned_set reads the watermark from the rules stream in
	 * the same call (two keys - illegal on a cluster): read the
	 * watermark separately and pass it into the single-key stamped
	 * variant instead, one extra round trip per write. A rule landing
	 * between the two reads at worst marks this item one rule too old -
	 * over-invalidation of a racing write, never stale data.
	 */
	#[Override]
	protected function setCall(
		string $id,
		string $value,
		array $tags,
		int $ttl,
	): mixed
	{
		if(($client = $this->getClient()) === null)
		{
			return false;
		}
		
		// the rules stream's last entry id, '0-0' when there are no rules
		$last = $client->xRevRange($this->getRulesKey(), '+', '-', 1);
		$mark = is_array($last) && count($last)
			? (string)array_key_first($last)
			: '0-0';
		
		return $this->functions
			->call('cache_versioned_set_stamped', [$id], [
				$value,
				implode(',', $tags),
				$ttl * 1000, // ms
				$this->rulesRetentionS * 1000, // ms
				$mark,
			]);
	}
	
	/**
	 * cache_clear is node-local (SCAN only sees the keys of the node it
	 * runs on), run it once per master - its 'allow-cross-slot-keys'
	 * flag makes touching that node's keys legal on a cluster
	 */
	#[Override]
	public function clearPhysical(): bool|int
	{
		if(($client = $this->getClient()) === null)
		{
			return false;
		}
		
		$prefix = $this->prefixer
			->prefix('*', $this->getGroup());
		$count = 0;
		
		try
		{
			// ensure the library is loaded on every master
			$this->functions->loadLibraries();
			
			$client->clearLastError();
			
			foreach($this->getConnection()->getMasters() as $master)
			{
				$result = $client->rawCommand($master,
					'FCALL',
					$this->functions->functionsPrefix('cache_clear'),
					'0',
					$prefix,
				);
				
				$count += (int)$result;
			}
			
			// rawCommand reports failures via the last error, not by throwing
			if($error = $client->getLastError())
			{
				$this->log($error);
				
				return false;
			}
		}
		catch(RedisException|RedisClusterException $exception)
		{
			$this->log($exception);
			
			return false;
		}
		
		return $count;
	}
}
