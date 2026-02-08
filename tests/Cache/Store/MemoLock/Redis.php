<?php
declare(strict_types=1);

namespace Tests\Cache\Store\MemoLock;

use Ovos\ArrayObject;
use Ovos\Container\Inject;
use Ovos\Cache\MemoLock\Redis as RedisMemoLock;
use Ovos\Cache\Store\KeyValue;
use Ovos\Cache\Store\Redis as Store;
use Ovos\Test;
use Ovos\Test\Internal;
use Ovos\Test\Parallel;
use Ovos\Test\Cache\Store\TraitRedis;
use Override;

use function sprintf;

/**
 * Redis
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Redis extends Test
{
	use TraitRedis;
	
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
		
		$this->store = $this->getStore(Store::class);
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
	
	public function setManualOverride(): bool
	{
		$queueEnabled = $this->store->isQueueEnabled();
		
		try
		{
			$this->store->setQueueEnabled(false);
			if($this->store->get(self::KEY_ITEM, queue: true) === null)
			{
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
	
	public function resolverWithTags(): bool
	{
		$value = 'test';
		$tags = ['tag1', 'tag2'];
		
		try
		{
			$value = $this->store->get(self::KEY_ITEM,
				resolver: fn() => $value,
				tags: $tags,
			);
			
			$result = $this->store->getTags(self::KEY_ITEM);
			
			return $result === $tags; // have the same key/value pairs in the same order and of the same types.
		}
		finally
		{
			$this->store->delete(self::KEY_ITEM);
		}
	}
	
	public function resolverModifyTags(): bool
	{
		$value = 'test';
		$tags = ['tag1', 'tag2'];
		
		try
		{
			$value = $this->store->get(self::KEY_ITEM,
				resolver: function($store, $key, &$ttl, &$tags) use ($value)
				{
					$tags[] = 'tag3';
					
					return $value;
				},
				tags: $tags,
			);
			
			$result = $this->store->getTags(self::KEY_ITEM);
			
			return $result !== $tags; // have the same key/value pairs in the same order and of the same types.
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
	
	public function immediateSet(): bool
	{
		$value = 'test';
		
		try
		{
			$result = $this->store->get(self::KEY_ITEM,
				resolver: fn() => $value,
				queue: false,
			);
			
			$exists = $this->store->get(self::KEY_ITEM, queue: false);
			
			return $exists !== null
				&& $result === $value;
		}
		finally
		{
			$this->store->delete(self::KEY_ITEM);
		}
	}
	
	public function noAction(): bool
	{
		try
		{
			$result = $this->store->get(self::KEY_ITEM,
				queue: false,
			);
			
			$exists = $this->store->get(self::KEY_ITEM, queue: false);
			
			return $exists === null
				&& $result === null;
		}
		finally
		{
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
			. DIRECTORY_SEPARATOR . 'Redis'
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
		$this->store->clear();
	}
	
	/**
	 * Called by the runner after all test methods have been invoked
	 */
	#[Internal]
	#[Override]
	public function deconstruct(): void
	{
		$this->store->getConnection()
			->disconnect();
	}
}
