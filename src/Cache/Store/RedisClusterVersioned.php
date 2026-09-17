<?php
declare(strict_types=1);

namespace Ovos\Cache\Store;

use Ovos\ArrayObject;
use Ovos\Connection\RedisCluster as ClusterConnection;
use Ovos\Connection\RedisCommon as Connection;
use Override;
use RedisClusterException;
use RedisException;

use function implode;

/**
 * RedisClusterVersioned
 *
 * The versioned (rule based) store on a Redis Cluster. The read path is
 * inherited unchanged - both stores fetch the item with one HMGET and
 * evaluate the cached rules in PHP. Only the write differs: the standalone
 * pairs the item and rules keys in one Lua call (two keys, illegal on a
 * cluster), so this store reads the watermark from the cached rules and
 * uses the single-key stamped set instead. Invalidation is inherited
 * untouched (a single-slot FCALL on the rules key).
 *
 * No RediSearch module is required - the tags live in the item hash.
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class RedisClusterVersioned extends RedisVersioned
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
	 * cache_versioned_set reads the watermark from the rules stream in the
	 * same call (two keys - illegal on a cluster). Pass the watermark into
	 * the single-key stamped variant instead, read from the locally cached
	 * rules (see watermark()) so a write reuses the read path's cache and
	 * avoids a separate XREVRANGE round trip.
	 */
	#[Override]
	protected function setCall(
		string $id,
		string $value,
		array $tags,
		int $ttl,
	): mixed
	{
		return $this->functions
			->call('cache_versioned_set_stamped', [$id], [
				$value,
				implode(',', $tags),
				$ttl * 1000, // ms
				$this->rulesRetentionS * 1000, // ms
				$this->watermark(),
			]);
	}
	
	/**
	 * The rules stream's last entry id ("<ms>-<seq>", "0-0" when empty),
	 * read from the held rules (rules_cache_ms) instead of a dedicated
	 * XREVRANGE - the read path already keeps them, so a write batch skips a
	 * round trip per write. A rule appended within the cache window leaves
	 * the watermark slightly behind: at worst this item is marked one or two
	 * rules too old - over-invalidation of a racing write, never stale data
	 * (the same trade the read cache already makes).
	 */
	protected function watermark(): string
	{
		return $this->getRules()
			->last();
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
		
		// the rules stream is wiped too - drop the locally cached rules so a
		// write that follows is not evaluated against rules that no longer exist
		$this->resetRulesCache();
		
		try
		{
			// cache_clear SCANs the whole node - use the long read timeout the
			// standalone clear uses, so a large group does not hit the default
			$this->getConnection()
				->toggleReadTimeout(Connection::TIMEOUT_READ_LONG);
			
			// ensure the library is loaded on every master; bail out if a node
			// could not be (re)loaded rather than FCALL against one missing it
			if($this->functions->loadLibraries() === false)
			{
				return false;
			}
			
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
		finally
		{
			// restore the default read timeout
			$this->getConnection()
				->toggleReadTimeout();
		}
		
		return $count;
	}
}
