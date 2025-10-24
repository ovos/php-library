<?php
declare(strict_types=1);

namespace Tests\Store\Redis;

use Ovos\ArrayObject;
use Ovos\Redis\Connection;
use Ovos\Store\Cache;
use Ovos\Store\Redis as RedisStore;
use Ovos\Test;
use Ovos\Test\Internal;
use RedisException;

use function Ovos\config;
use function sprintf;

/**
 * Queue
 *
 * @package Tests
 * @author Marcin Gil <mg@ovos.at>
 */
class Queue extends Test
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
	public const int CLIENTS = 3;
	
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
		
		if($this->_config->getPath(['persistent', 'queue', 'enabled']) !== true)
		{
			$this->setIsDisabled(true,
				sprintf('"queue" is not enabled in cache config.')
			);
			
			return;
		}
		
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
			Cache::GROUP_TESTS,
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
	
	public function set(): bool
	{
		try
		{
			if($this->_store->get(self::KEY_ITEM) === null)
			{
				$this->_store->set(self::KEY_ITEM, 'test');
			}
			
			$exists = $this->_store->get(self::KEY_ITEM);
			
			return $exists !== null;
		}
		finally
		{
			$this->_store->delete(self::KEY_ITEM);
		}
	}
	
	public function setCallback(): bool
	{
		$value = 'test';
		
		try
		{
			$result = $this->_store->get(self::KEY_ITEM,
				setCallback: fn() => $value,
			);
			
			return $result === $value;
		}
		finally
		{
			$this->_store->delete(self::KEY_ITEM);
		}
	}
	
	public function setCallbackWithTags(): bool
	{
		$value = 'test';
		$tags = ['tag1', 'tag2'];
		
		try
		{
			$value = $this->_store->get(self::KEY_ITEM,
				setCallback: fn() => $value,
				tags: $tags,
			);
			
			$result = $this->_store->getTags(self::KEY_ITEM);
			
			return $result === $tags; // have the same key/value pairs in the same order and of the same types.
		}
		finally
		{
			$this->_store->delete(self::KEY_ITEM);
		}
	}
	
	public function releaseActiveLock(): bool
	{
		try
		{
			if($this->_store->get(self::KEY_ITEM) === null)
			{
				$this->_store->releaseActiveLock(self::KEY_ITEM);
			}
			
			$lockKey = $this->_store
				->prefix(RedisStore::TYPE_LOCK, self::KEY_ITEM);
			
			return $this->_store->getClient()
				->exists($lockKey) === 0;
		}
		finally
		{
			$this->_store->delete(self::KEY_ITEM);
		}
	}
	
	public function renewLock(): bool
	{
		try
		{
			if($this->_store->get(self::KEY_ITEM) === null)
			{
				$this->_store->renewLock(self::KEY_ITEM);
				$this->_store->set(self::KEY_ITEM, 'value'); // to release the lock
			}
			
			return true;
		}
		finally
		{
			$this->_store->delete(self::KEY_ITEM);
		}
	}
	
	public function queue(): bool
	{
		$phpBinary = config()->getPath(['cli', 'executable']);
		$phpBinary = $phpBinary ?? 'php';
		$command = sprintf('%s %s %s', $phpBinary,
			__DIR__ . DIRECTORY_SEPARATOR
			. 'Queue' . DIRECTORY_SEPARATOR
			. 'QueueClient.file.php',
			Cache::GROUP_TESTS,
		);
		
		try
		{
			self::parallel($command, self::CLIENTS);
			
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
	
	public static function parallel(string $command, int $amount): void
	{
		$processes = [];
		for($i = 0; $i < $amount; $i++)
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
	}
	
	/**
	 * Called by the runner after all test methods have been invoked
	 */
	#[Internal]
	public function deconstruct(): void
	{
		$this->_store->clear();
		$this->_connection->disconnect();
	}
}
