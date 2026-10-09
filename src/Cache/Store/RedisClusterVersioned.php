<?php
declare(strict_types=1);

namespace Ovos\Cache\Store;

use Ovos\ArrayObject;
use Ovos\Connection\RedisCluster as ClusterConnection;
use Ovos\Connection\RedisCommon as Connection;
use Override;
use RedisClusterException;
use RedisException;

/**
 * RedisClusterVersioned
 *
 * The versioned (rule based) store on a Redis Cluster. The read path is
 * inherited unchanged - both stores fetch the item with one HMGET and
 * evaluate the cached rules in PHP - and so is the write: one single-key
 * stamped set, the watermark taken from the cached rules. Invalidation is
 * inherited untouched (a single-slot FCALL on the rules key); clearing runs
 * per master.
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
