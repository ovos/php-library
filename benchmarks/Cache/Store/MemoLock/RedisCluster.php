<?php
declare(strict_types=1);

namespace Benchmarks\Cache\Store\MemoLock;

use Ovos\ArrayObject;
use Ovos\Benchmark;
use Ovos\Container\Inject;
use Ovos\Cache\Store\KeyValue;
use Ovos\Cache\Store\RedisCluster as Store;
use Ovos\Test\Internal;
use Ovos\Test\Parallel;
use Ovos\Test\Cache\Store\TraitRedisCluster;
use Override;

use function sprintf;
use function dirname;

/**
 * RedisCluster
 *
 * The parallel MemoLock stampede benchmark against a Redis Cluster:
 * many clients ask for the same cold key, exactly one may resolve it
 * while the rest wait on the pub/sub queue - with the cross-node twist
 * that waiters subscribe via one node and the release publishes on the
 * node owning the lock key's slot.
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class RedisCluster extends Benchmark
{
	use TraitRedisCluster;
	
	public const string KEY_ITEM = 'item';
	
	public const string KEY_ITEM_COUNTER = 'item:counter';
	
	public const int CLIENTS = 100;
	
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
		
		$this->group = KeyValue::GROUP_BENCHMARKS;
		$this->store = $this->getClusterStore();
		
		if($this->store === null)
		{
			$this->setDisabled(true, $this->clusterUnavailableReason);
		}
	}
	
	public function queue(): bool
	{
		$phpBinary = $this->config->getPath(['cli', 'executable']);
		$phpBinary = $phpBinary ?? 'php';
		$command = sprintf('%s %s %s', $phpBinary,
			dirname(__DIR__, 4) . DIRECTORY_SEPARATOR
			. 'tests' . DIRECTORY_SEPARATOR
			. 'Cache' . DIRECTORY_SEPARATOR
			. 'Store' . DIRECTORY_SEPARATOR
			. 'MemoLock' . DIRECTORY_SEPARATOR
			. 'RedisCluster' . DIRECTORY_SEPARATOR
			. 'Client.file.php',
			KeyValue::GROUP_BENCHMARKS,
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
