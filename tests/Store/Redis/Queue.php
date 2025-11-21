<?php
declare(strict_types=1);

namespace Tests\Store\Redis;

use Ovos\ArrayObject;
use Ovos\Connection\Redis as Connection;
use Ovos\Connections;
use Ovos\Container\ArrayObject as InjectArrayObject;
use Ovos\Container\Inject;
use Ovos\Store\KeyValue;
use Ovos\Store\Redis as Store;
use Ovos\Test;
use Ovos\Test\Internal;
use Ovos\Test\Parallel;
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
	#[Inject('config')]
	#[InjectArrayObject('cache')]
	protected ArrayObject $_config;
	
	/**
	 * @var ?Connection
	 */
	protected ?Connection $_connection = null;
	
	/**
	 * @var ?Store
	 */
	protected ?Store $_store = null;
	
	public function __construct()
	{
		if($this->_config->getPath(['persistent', 'queue', 'enabled']) !== true)
		{
			$this->setIsDisabled(true,
				sprintf('"queue" is not enabled in cache config.')
			);
			
			return;
		}
		
		$this->_connection = $this->_container
			->getClass(Connections::class)
			->get($this->_config->persistent->connection);
		
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
		$this->_store = new Store
		(
			$this->_connection,
			$this->_config->persistent,
			$this->_config->prefix,
			KeyValue::GROUP_TESTS,
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
			
			$exists = $this->_store->get(self::KEY_ITEM, queue: false);
			
			return $exists !== null;
		}
		finally
		{
			$this->_store->delete(self::KEY_ITEM);
		}
	}
	
	public function setManualOverride(): bool
	{
		$queueEnabled = $this->_store->isQueueEnabled();
		
		try
		{
			$this->_store->setQueueEnabled(false);
			if($this->_store->get(self::KEY_ITEM, queue: true) === null)
			{
				$this->_store->set(self::KEY_ITEM, 'test');
			}
			
			$exists = $this->_store->get(self::KEY_ITEM, queue: false);
			
			return $exists !== null;
		}
		finally
		{
			$this->_store->setQueueEnabled($queueEnabled);
			$this->_store->delete(self::KEY_ITEM);
		}
	}
	
	public function setManual(): bool
	{
		$queueEnabled = $this->_store->isQueueEnabled();
		
		try
		{
			$this->_store->setQueueEnabled(false);
			if($this->_store->get(self::KEY_ITEM) === null)
			{
				$this->_store->queue(self::KEY_ITEM); // manual queue call
				$this->_store->set(self::KEY_ITEM, 'test');
			}
			
			$exists = $this->_store->get(self::KEY_ITEM, queue: false);
			
			return $exists !== null;
		}
		finally
		{
			$this->_store->setQueueEnabled($queueEnabled);
			$this->_store->delete(self::KEY_ITEM);
		}
	}
	
	public function resolver(): bool
	{
		$value = 'test';
		
		try
		{
			$result = $this->_store->get(self::KEY_ITEM,
				resolver: fn() => $value,
			);
			
			return $result === $value;
		}
		finally
		{
			$this->_store->delete(self::KEY_ITEM);
		}
	}
	
	public function resolverWithTags(): bool
	{
		$value = 'test';
		$tags = ['tag1', 'tag2'];
		
		try
		{
			$value = $this->_store->get(self::KEY_ITEM,
				resolver: fn() => $value,
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
	
	public function resolverModifyTags(): bool
	{
		$value = 'test';
		$tags = ['tag1', 'tag2'];
		
		try
		{
			$value = $this->_store->get(self::KEY_ITEM,
				resolver: function($store, $key, &$ttl, &$tags) use ($value)
				{
					$tags[] = 'tag3';
					
					return $value;
				},
				tags: $tags,
			);
			
			$result = $this->_store->getTags(self::KEY_ITEM);
			
			return $result !== $tags; // have the same key/value pairs in the same order and of the same types.
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
			
			$id = $this->_store
				->prefix(self::KEY_ITEM, $this->_store->getType());
			$lockKey = $this->_store
				->prefix(Store::TYPE_LOCK, $id);
			
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
	
	public function immediateSet(): bool
	{
		$value = 'test';
		
		try
		{
			$result = $this->_store->get(self::KEY_ITEM,
				resolver: fn() => $value,
				queue: false,
			);
			
			$exists = $this->_store->get(self::KEY_ITEM, queue: false);
			
			return $exists !== null
				&& $result === $value;
		}
		finally
		{
			$this->_store->delete(self::KEY_ITEM);
		}
	}
	
	public function noAction(): bool
	{
		try
		{
			$result = $this->_store->get(self::KEY_ITEM,
				queue: false,
			);
			
			$exists = $this->_store->get(self::KEY_ITEM, queue: false);
			
			return $exists === null
				&& $result === null;
		}
		finally
		{
		}
	}
	
	public function lockOnly(): bool
	{
		$id = $this->_store
			->prefix(self::KEY_ITEM, $this->_store->getType());
		$lockKey = $this->_store
			->prefix(Store::TYPE_LOCK, $id);
		
		try
		{
			$this->_store->queue(self::KEY_ITEM, lockOnly: true);
			
			$exists = $this->_store->getClient()
				->exists($lockKey) === 1;
			
			$this->_store->releaseActiveLock(self::KEY_ITEM);
			
			$existsNot = $this->_store->getClient()
				->exists($lockKey) === 0;
			
			return $exists && $existsNot;
		}
		finally
		{
		}
	}
	
	public function queue(): bool
	{
		$phpBinary = config()->getPath(['cli', 'executable']);
		$phpBinary = $phpBinary ?? 'php';
		$command = sprintf('%s %s %s', $phpBinary,
			__DIR__
			. DIRECTORY_SEPARATOR . 'Queue'
			. DIRECTORY_SEPARATOR . 'QueueClient.file.php',
			KeyValue::GROUP_TESTS,
		);
		
		try
		{
			Parallel::run($command, self::CLIENTS);
			
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
		$this->_store->clear();
		$this->_connection->disconnect();
	}
}
