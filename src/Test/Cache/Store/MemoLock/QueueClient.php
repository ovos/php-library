<?php
declare(strict_types=1);

namespace Ovos\Test\Cache\Store\MemoLock;

use Ovos\ArrayObject;
use Ovos\Cache\Store\KeyValue;
use Ovos\Cache\Store\KeyValue\Redis as KeyValueRedis;
use Ovos\Container\Inject;
use Ovos\Controller;

use function getmypid;
use function microtime;
use function sleep;

/**
 * QueueClient
 *
 * Shared body of the parallel cache-queue (MemoLock stampede) test
 * clients. The standalone and the cluster variant differ only in which
 * store they build - the resolver every spawned process runs lives here
 * once, so the two cannot silently drift apart.
 *
 * Each concrete subclass is a standalone "*.file.php" script launched as
 * its own OS process by the queue test (see Parallel::run). The bootstrap
 * (BASE_DIR / init.php) and the bottom launcher must stay in that file:
 * the autoloader that finds this base only exists once init.php has run.
 * The subclass mixes in TraitRedis / TraitRedisCluster (which provide the
 * $group property and the store factory) and implements createStore().
 *
 * @author Marcin Gil <mg@ovos.at>
 */
abstract class QueueClient extends Controller\Cli
{
	public const string KEY_ITEM = 'item';
	
	public const string KEY_ITEM_COUNTER = 'item:counter';
	
	#[Inject('config')]
	protected ArrayObject $config;
	
	protected ?KeyValueRedis $store = null;
	
	public function __construct()
	{
		parent::__construct();
		
		$this->config->system->profilers->enabled = false;
		
		// $group is declared by TraitRedis on the concrete subclass; the
		// launcher passes the group as the first CLI argument so the client
		// shares the group of the parent test
		$this->group = $_SERVER['argv'][1] ?? KeyValue::GROUP_TESTS;
		$this->store = $this->createStore();
	}
	
	/**
	 * Builds the store under test (standalone or cluster);
	 * returns null when the backing connection is unavailable
	 */
	abstract protected function createStore(): ?KeyValueRedis;
	
	/**
	 * Runs the queue client: one get() whose resolver sleeps to widen the
	 * stampede window, then bumps a shared counter and writes the item.
	 * MemoLock must let exactly one of the parallel processes resolve.
	 */
	public function run(): void
	{
		// the parent test is skipped when the store is unavailable,
		// guard anyway in case the client is launched by hand
		if($this->store === null)
		{
			return;
		}
		
		$this->store->get(self::KEY_ITEM,
			resolver: function(KeyValueRedis $store)
			{
				$client = $store->getClient();
				if($client === null)
				{
					return;
				}
				
				sleep(1);
				
				$id = $store->prefix(self::KEY_ITEM_COUNTER,
					$store->getType()
				);
				
				$client->incr($id);
				$client->expire($id, 30);
				
				$value = [
					'pid' => getmypid(),
					'time' => microtime(true),
				];
				$store->set(self::KEY_ITEM, $value, 30);
			},
		);
	}
}
