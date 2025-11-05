<?php
declare(strict_types=1);

namespace Tests\Store;

use Ovos\ArrayObject;
use Ovos\Store\KeyValue;
use Ovos\Store\Apcu as Store;
use Ovos\Test;
use Ovos\Test\Internal;
use Ovos\Test\Parallel;

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
	 * @var ?Store
	 */
	protected ?Store $_store = null;
	
	public function __construct()
	{
		$this->_config = config()->cache;
		
		if($this->_config->getPath(['perishable', 'queue', 'enabled']) !== true)
		{
			$this->setIsDisabled(true,
				sprintf('"queue" is not enabled in cache config.')
			);
			
			return;
		}
	}
	
	protected function _initStore(): void
	{
		$this->_store = Store::fromConfig(
			$this->_config,
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
	
	public function releaseActiveLock(): bool
	{
		try
		{
			if($this->_store->get(self::KEY_ITEM) === null)
			{
				$this->_store->releaseActiveLock(self::KEY_ITEM);
			}
			
			$id = $this->_store
				->prefix(self::KEY_ITEM, $this->_store->getGroup());
			$lockKey = $this->_store
				->prefix(Store::TYPE_LOCK, $id);
			
			return apcu_exists($lockKey) === false;
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
			->prefix(self::KEY_ITEM, $this->_store->getGroup());
		
		$lockKey = $this->_store
			->prefix(Store::TYPE_LOCK, $id);
		
		try
		{
			$this->_store->queue(self::KEY_ITEM, lockOnly: true);
			
			$exists = apcu_exists($lockKey) === true;
			
			$this->_store->releaseActiveLock(self::KEY_ITEM);
			
			$existsNot = apcu_exists($lockKey)=== false;
			
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
	public function deconstruct(): void
	{
		$this->_store->clear();
	}
}
