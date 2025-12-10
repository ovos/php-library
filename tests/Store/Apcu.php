<?php
declare(strict_types=1);

namespace Tests\Store;

use Ovos\ArrayObject;
use Ovos\Store\KeyValue;
use Ovos\Store\Apcu as Store;
use Ovos\Test;
use Ovos\Test\Internal;
use Override;

use function Ovos\config;
use function sprintf;

/**
 * Apcu
 *
 * @package Tests
 * @author Marcin Gil <mg@ovos.at>
 */
class Apcu extends Test
{
	public const string KEY_ITEM = 'item';
	
	public const string KEY_ITEM_COUNTER = 'item:counter';
	
	public const int CLIENTS = 3;
	
	protected ArrayObject $config;
	
	protected ?Store $store = null;
	
	public function __construct()
	{
		$this->config = config()->cache;
		
		if($this->config->getPath(['perishable', 'queue', 'enabled']) !== true)
		{
			$this->setDisabled(true,
			'"queue" is not enabled in cache config.'
			);
			
			return;
		}
	}
	
	protected function initStore(): void
	{
		$this->store = Store::fromConfig(
			$this->config,
			KeyValue::GROUP_TESTS,
		);
	}
	
	/**
	 * Called by the runner before each test method
	 */
	#[Internal]
	#[Override]
	public function prepare(): void
	{
		$this->initStore();
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
	
	public function releaseActiveLock(): bool
	{
		try
		{
			if($this->store->get(self::KEY_ITEM) === null)
			{
				$this->store->releaseActiveLock(self::KEY_ITEM);
			}
			
			$id = $this->store
				->prefix(self::KEY_ITEM, $this->store->getGroup());
			$lockKey = $this->store
				->prefix(Store::TYPE_LOCK, $id);
			
			return apcu_exists($lockKey) === false;
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
		$id = $this->store
			->prefix(self::KEY_ITEM, $this->store->getGroup());
		
		$lockKey = $this->store
			->prefix(Store::TYPE_LOCK, $id);
		
		try
		{
			$this->store->lockAndQueue(self::KEY_ITEM, lockOnly: true);
			
			$exists = apcu_exists($lockKey) === true;
			
			$this->store->releaseActiveLock(self::KEY_ITEM);
			
			$existsNot = apcu_exists($lockKey) === false;
			
			return $exists && $existsNot;
		}
		finally
		{
		}
	}
	
	/**
	 * Called by the runner after all test methods have been invoked
	 */
	#[Internal]
	#[Override]
	public function deconstruct(): void
	{
		$this->store->clear();
	}
}
