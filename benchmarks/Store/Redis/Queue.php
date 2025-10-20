<?php
declare(strict_types=1);

namespace Benchmarks\Store\Redis;

use Ovos\ArrayObject;
use Ovos\Benchmark;
use Ovos\Redis\Connection;
use Ovos\Store\Cache;
use Ovos\Store\Redis as RedisStore;
use Ovos\Test\Internal;
use RedisException;

use function Ovos\config;
use function sprintf;

/**
 * Queue
 *
 * @package Bechmarks
 * @author Marcin Gil <mg@ovos.at>
 */
class Queue extends Benchmark
{
	/**
	 * @var string
	 */
	public const string KEY_ITEM = 'item';
	
	/**
	 * @var string
	 */
	public const string KEY_ITEM_COUNTER = 'item:counter';
	
	/**
	 * @var int
	 */
	public const int CLIENTS = 100;
	
	/**
	 * @var ArrayObject
	 */
	protected ArrayObject $_config;
	
	/**
	 * @var ?Connection
	 */
	protected ?Connection $_connection = null;
	
	/**
	 * @var ?RedisStore
	 */
	protected ?RedisStore $_store = null;
	
	public function __construct()
	{
		$this->_config = config()->cache;
		
		$this->_connection = new Connection($this->_config->persistent);
		if($this->_connection->connect() === false)
		{
			throw new RedisException
			(
				sprintf('Could not connect to redis server "%s" on port "%s".',
					$this->_store->getConfig()->host,
					$this->_store->getConfig()->port,
				)
			);
		}
	}
	
	protected function _initStore(): void
	{
		$this->_store = new RedisStore
		(
			$this->_config->prefix,
			$this->_connection,
			$this->_config->persistent,
			Cache::GROUP_BENCHMARKS,
		);
	}
	
	/**
	 * Called by the runner before each test method
	 */
	#[Internal]
	public function prepare(): void
	{
		$this->_initStore();
	}
	
	public function queue(): bool
	{
		$phpBinary = config()->getPath(['cli', 'executable']);
		$phpBinary = $phpBinary ?? 'php';
		$command = sprintf('%s %s %s', $phpBinary,
			dirname(__DIR__, 3) . DIRECTORY_SEPARATOR
			. 'tests' . DIRECTORY_SEPARATOR
			. 'Store' . DIRECTORY_SEPARATOR
			. 'Redis' . DIRECTORY_SEPARATOR
			. 'Queue' . DIRECTORY_SEPARATOR
			. 'QueueClient.file.php',
			Cache::GROUP_BENCHMARKS,
		);
		
		try
		{
			$processes = [];
			for($i = 0; $i < self::CLIENTS; $i++)
			{
				$process = proc_open($command, [], $pipes[]);
				if(is_resource($process))
				{
					$processes[] = $process;
				}
			}
			
			// wait for all processes to finish
			$running = true;
			while($running)
			{
				$running = false;
				foreach($processes as $process)
				{
					if(is_resource($process) === false)
					{
						continue;
					}
					
					$status = proc_get_status($process);
					if($status['running'])
					{
						$running = true;
						usleep(10000); // wait 10ms before checking again
					}
					else
					{
						proc_close($process);
					}
				}
			}
			
			$id = $this->_store->prefix(self::KEY_ITEM_COUNTER,
				$this->_store->getType()
			);
			
			$count = $this->_store->getClient()->get($id);
			return (int)$count === 1;
		}
		finally
		{
			$this->_store->delete(self::KEY_ITEM);
			$this->_store->delete(self::KEY_ITEM_COUNTER);
		}
	}
	
	/**
	 * Called by the runner after each test method
	 */
	#[Internal]
	public function finalize(): void
	{
		$this->_store->clear();
	}
	
	/**
	 * Called by the runner after all test methods have been invoked
	 */
	#[Internal]
	public function deconstruct(): void
	{
		$this->_connection->disconnect();
	}
}
