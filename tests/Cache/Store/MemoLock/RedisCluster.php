<?php
declare(strict_types=1);

namespace Tests\Cache\Store\MemoLock;

use Ovos\ArrayObject;
use Ovos\Container\Inject;
use Ovos\Cache\MemoLock\Redis as RedisMemoLock;
use Ovos\Cache\Store\KeyValue;
use Ovos\Cache\Store\RedisClusterVersioned as Store;
use Ovos\Test;
use Ovos\Test\Internal;
use Ovos\Test\Parallel;
use Ovos\Test\Cache\Store\TraitRedisCluster;
use Override;

use function sprintf;

/**
 * RedisCluster
 *
 * MemoLock semantics on a real Redis Cluster: locks and FCALL release
 * route to the node owning the lock key's slot, while the queue test
 * proves the cross-node wakeup - waiters subscribe via one node (the
 * standalone "redis_cluster_queue" connection) and the lock release
 * publishes on whichever node owns the lock key, relying on the
 * cluster-wide PUBLISH broadcast.
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class RedisCluster extends Test
{
	use TraitRedisCluster;
	
	public const string KEY_ITEM = 'item';
	
	public const string KEY_ITEM_COUNTER = 'item:counter';
	
	public const int CLIENTS = 3;
	
	#[Inject('config')]
	protected ArrayObject $config;
	
	protected ?Store $store = null;
	
	public function __construct()
	{
		if($this->cacheConfig->getPath(['persistent', 'queue', 'enabled']) !== true)
		{
			$this->setDisabled(true,
				sprintf('"queue" is not enabled in cache config.')
			);
			
			return;
		}
		
		$this->store = $this->getClusterStore();
		
		if($this->store === null)
		{
			$this->setDisabled(true, $this->clusterUnavailableReason);
		}
	}
	
	public function set(): bool
	{
		try
		{
			if($this->store->get(self::KEY_ITEM) === null)
			{
				$this->store->set(self::KEY_ITEM, 'test');
			}
			
			$exists = $this->store->get(self::KEY_ITEM, queue: false);
			
			return $exists !== null;
		}
		finally
		{
			$this->store->delete(self::KEY_ITEM);
		}
	}
	
	public function setManual(): bool
	{
		$queueEnabled = $this->store->isQueueEnabled();
		
		try
		{
			$this->store->setQueueEnabled(false);
			if($this->store->get(self::KEY_ITEM) === null)
			{
				$this->store->lockAndQueue(self::KEY_ITEM); // manual queue call
				$this->store->set(self::KEY_ITEM, 'test');
			}
			
			$exists = $this->store->get(self::KEY_ITEM, queue: false);
			
			return $exists !== null;
		}
		finally
		{
			$this->store->setQueueEnabled($queueEnabled);
			$this->store->delete(self::KEY_ITEM);
		}
	}
	
	public function resolver(): bool
	{
		$value = 'test';
		
		try
		{
			$result = $this->store->get(self::KEY_ITEM,
				resolver: fn() => $value,
			);
			
			return $result === $value;
		}
		finally
		{
			$this->store->delete(self::KEY_ITEM);
		}
	}
	
	public function releaseActiveLock(): bool
	{
		try
		{
			if($this->store->get(self::KEY_ITEM) === null)
			{
				$this->store->releaseActiveLock(self::KEY_ITEM);
			}
			
			$id = $this->store->prefix(self::KEY_ITEM, $this->store->getType());
			$lockKey = $this->store->prefix(RedisMemoLock::TYPE_LOCK, $id);
			
			return $this->store->getClient()
				->exists($lockKey) === 0;
		}
		finally
		{
			$this->store->delete(self::KEY_ITEM);
		}
	}
	
	public function renewLock(): bool
	{
		try
		{
			if($this->store->get(self::KEY_ITEM) === null)
			{
				$this->store->renewLock(self::KEY_ITEM);
				$this->store->set(self::KEY_ITEM, 'value'); // to release the lock
			}
			
			return true;
		}
		finally
		{
			$this->store->delete(self::KEY_ITEM);
		}
	}
	
	public function lockOnly(): bool
	{
		$id = $this->store->prefix(self::KEY_ITEM, $this->store->getType());
		$lockKey = $this->store->prefix(RedisMemoLock::TYPE_LOCK, $id);
		
		try
		{
			$this->store->lockAndQueue(self::KEY_ITEM);
			
			$exists = $this->store->getClient()
				->exists($lockKey) === 1;
			
			$this->store->releaseActiveLock(self::KEY_ITEM);
			
			$existsNot = $this->store->getClient()
				->exists($lockKey) === 0;
			
			return $exists && $existsNot;
		}
		finally
		{
		}
	}
	
	public function lockAndResolve(): bool
	{
		try
		{
			$result = $this->store->lockAndQueue(self::KEY_ITEM,
				function(Store $store)
				{
					$store->set(self::KEY_ITEM, 'test');
					
					return 'test';
				}
			);
			
			$exists = $this->store->get(self::KEY_ITEM, queue: false);
			
			return $result === 'test'
				&& $exists === 'test';
		}
		finally
		{
			$this->store->releaseActiveLock(self::KEY_ITEM);
			$this->store->delete(self::KEY_ITEM);
		}
	}
	
	public function queue(): bool
	{
		$phpBinary = $this->config->getPath(['cli', 'executable']);
		$phpBinary = $phpBinary ?? 'php';
		$command = sprintf('%s %s %s', $phpBinary,
			__DIR__
			. DIRECTORY_SEPARATOR . 'RedisCluster'
			. DIRECTORY_SEPARATOR . 'Client.file.php',
			KeyValue::GROUP_TESTS,
		);
		
		try
		{
			Parallel::run($command, self::CLIENTS);
			
			$id = $this->store->prefix(self::KEY_ITEM_COUNTER,
				$this->store->getType(),
			);
			
			$count = $this->store->getClient()->get($id);
			
			return (int)$count === 1;
		}
		finally
		{
			$this->store->delete(self::KEY_ITEM);
			$this->store->delete(self::KEY_ITEM_COUNTER);
		}
	}
	
	/**
	 * Called by the runner after each test method
	 */
	#[Internal]
	#[Override]
	public function finalize(): void
	{
		$this->store?->clear();
	}
	
	/**
	 * Called by the runner after all test methods have been invoked
	 */
	#[Internal]
	#[Override]
	public function deconstruct(): void
	{
		$this->store?->getConnection()
			->disconnect();
	}
}
