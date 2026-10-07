<?php
declare(strict_types=1);

namespace Ovos\Test\Cache\Store\MemoLock;

use Closure;
use Ovos\ArrayObject;
use Ovos\Cache\Store\KeyValue;
use Ovos\Cache\Store\KeyValue\Redis as KeyValueRedis;
use Ovos\Container\Inject;
use Ovos\Controller;

use function getmypid;
use function json_encode;
use function microtime;
use function sleep;

/**
 * QueueClient
 *
 * Shared body of the parallel cache-queue (MemoLock stampede) test
 * clients. The standalone and the cluster variant differ only in which
 * store they build - the work every spawned process runs lives here
 * once, so the two cannot silently drift apart.
 *
 * Each concrete subclass is a standalone "*.file.php" script launched as
 * its own OS process by the queue test (see Parallel::run). The bootstrap
 * (BASE_DIR / init.php) and the bottom launcher must stay in that file:
 * the autoloader that finds this base only exists once init.php has run.
 * The subclass mixes in TraitRedis / TraitRedisCluster (which provide the
 * $group property and the store factory) and implements createStore().
 *
 * The launcher passes the group as the first CLI argument and the mode as
 * the second (see run()).
 *
 * @author Marcin Gil <mg@ovos.at>
 */
abstract class QueueClient extends Controller\Cli
{
	public const string KEY_ITEM = 'item';
	
	public const string KEY_ITEM_COUNTER = 'item:counter';
	
	public const string KEY_ITEM_RESULTS = 'item:results';
	
	public const string KEY_SECTION = 'section';
	
	public const string KEY_SECTION_INSIDE = 'section:inside';
	
	public const string KEY_SECTION_OVERLAPS = 'section:overlaps';
	
	public const string KEY_SECTION_RUNS = 'section:runs';
	
	/**
	 * One get() with a resolver - the stampede
	 */
	public const string MODE_GET = 'get';
	
	/**
	 * A critical section under the store's lock-only lockAndQueue()
	 */
	public const string MODE_SECTION = 'section';
	
	/**
	 * The same critical section under MemoLock alone, with no fetcher
	 */
	public const string MODE_STANDALONE = 'standalone';
	
	/**
	 * The manual lock pattern: get(queue: false), lockAndQueue(), set()
	 */
	public const string MODE_MANUAL = 'manual';
	
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
	 * Runs the client in the mode the launcher names (MODE_*, the second
	 * CLI argument; MODE_GET when there is none)
	 */
	public function run(): void
	{
		// the parent test is skipped when the store is unavailable,
		// guard anyway in case the client is launched by hand
		if($this->store === null || $this->store->getClient() === null)
		{
			return;
		}
		
		$id = $this->key(self::KEY_SECTION);
		
		match($_SERVER['argv'][2] ?? self::MODE_GET)
		{
			self::MODE_SECTION => $this->section(
				fn() => $this->store->lockAndQueue(self::KEY_SECTION),
				fn() => $this->store->releaseActiveLock(self::KEY_SECTION),
			),
			self::MODE_STANDALONE => $this->section(
				fn() => $this->store->getMemoLock()->lockAndQueue($id, queue: true),
				fn() => $this->store->getMemoLock()->releaseActiveLock($id),
			),
			self::MODE_MANUAL => $this->manual(),
			default => $this->stampede(),
		};
	}
	
	/**
	 * One get() whose resolver sleeps to widen the stampede window, then
	 * bumps a shared counter and writes the item. MemoLock must let exactly
	 * one of the parallel processes resolve.
	 */
	protected function stampede(): void
	{
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
	
	/**
	 * A critical section under a lock-only lock: every process counts itself
	 * in and out, and one that finds another process inside counts an
	 * overlap - a mutex leaves none
	 */
	protected function section(
		Closure $lock,
		Closure $release,
	): void
	{
		$client = $this->store->getClient();
		
		$lock();
		try
		{
			if($client->incr($this->key(self::KEY_SECTION_INSIDE)) > 1)
			{
				$client->incr($this->key(self::KEY_SECTION_OVERLAPS));
			}
			
			// longer than the spawn spread: the others are queued by now
			sleep(1);
			
			$client->decr($this->key(self::KEY_SECTION_INSIDE));
			$client->incr($this->key(self::KEY_SECTION_RUNS));
		}
		finally
		{
			$release();
		}
	}
	
	/**
	 * The manual lock pattern (README.MEMOLOCK.md): the work runs only when
	 * lockAndQueue() hands nothing back - this process holds the lock. Every
	 * process records the value it ended with
	 */
	protected function manual(): void
	{
		if(($value = $this->store->get(self::KEY_ITEM, queue: false)) === null
			&& ($value = $this->store->lockAndQueue(self::KEY_ITEM)) === null
		)
		{
			sleep(1);
			
			$this->store->getClient()
				->incr($this->key(self::KEY_ITEM_COUNTER));
			
			$value = [
				'pid' => getmypid(),
			];
			$this->store->set(self::KEY_ITEM, $value, 30);
		}
		
		$this->store->getClient()
			->rPush($this->key(self::KEY_ITEM_RESULTS), json_encode($value));
	}
	
	/**
	 * The store's id of a raw key the processes share
	 */
	protected function key(
		string $name,
	): string
	{
		return $this->store->prefix($name, $this->store->getType());
	}
}
