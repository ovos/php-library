<?php
declare(strict_types=1);

namespace Tests\Cache\Store;

use Ovos\ArrayObject;
use Ovos\Cache\MemoLock\Apcu as ApcuMemoLock;
use Ovos\Cache\Store\KeyValue;
use Ovos\Cache\Store\Apcu as Store;
use Ovos\Test;
use Ovos\Test\Internal;
use Ovos\Test\Cache\Store\TraitStaleWhileRevalidate;
use Ovos\Test\Cache\Store\TraitStoredStrings;
use Override;

use function Ovos\config;

use function apcu_fetch;
use function is_string;

/**
 * Apcu
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Apcu extends Test
{
	use TraitStaleWhileRevalidate;
	use TraitStoredStrings;
	
	public const string KEY_ITEM = 'item';
	
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
		$this->store = new Store(
			$this->config->prefix,
			$this->config->perishable,
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
				// manual queue call
				$this->store->lockAndQueue(self::KEY_ITEM);
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
			
			$id = $this->store->prefix(self::KEY_ITEM, $this->store->getGroup());
			$lockKey = $this->store->prefix(ApcuMemoLock::TYPE_LOCK, $id);
			
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
			->prefix(ApcuMemoLock::TYPE_LOCK, $id);
		
		try
		{
			$this->store->lockAndQueue(self::KEY_ITEM);
			
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
	
	/**
	 * RULE: the invalidation guard (see KeyValue::rememberMiss()) - a value
	 * computed before another instance deleted its key is never served after
	 * it; a write-through is written; an instance's own delete after its miss
	 * does not refuse its own write
	 */
	public function theInvalidationGuardHolds(): bool
	{
		$key = 'guard-item';
		$reader = $this->newStore();
		$writer = $this->newStore();
		$writer->delete($key);
		
		$reader->get($key, queue: false);
		$writer->delete($key);
		$refused = $reader->set($key, 'stale', 60) === false
			&& $this->newStore()->get($key, queue: false) === null;
		
		$through = $reader->set($key, 'through', 60)
			&& $this->newStore()->get($key, queue: false) === 'through';
		$writer->delete($key);
		
		$own = $this->newStore();
		$own->get($key, queue: false);
		$own->delete($key);
		$ownWritten = $own->set($key, 'own', 60)
			&& $this->newStore()->get($key, queue: false) === 'own';
		$writer->delete($key);
		
		return $refused && $through && $ownWritten;
	}
	
	/**
	 * A fresh store - another process sharing this APCu
	 * (TraitStaleWhileRevalidate)
	 */
	protected function staleStore(): Store
	{
		return $this->newStore();
	}

	/**
	 * A fresh store - another process sharing this APCu
	 */
	protected function newStore(): Store
	{
		return new Store(
			$this->config->prefix,
			$this->config->perishable,
			KeyValue::GROUP_TESTS,
		);
	}
	
	/**
	 * RULE: a delete landing between the guard's check and the store is
	 * caught after it - the value is taken back, nothing stale stays
	 */
	public function aDeleteRacingTheStoreIsTakenBack(): bool
	{
		$key = 'guard-race';
		$writer = $this->newStore();
		$reader = new class($this->config->prefix, $this->config->perishable, KeyValue::GROUP_TESTS) extends Store
		{
			public ?Store $other = null;
			
			protected function storeValue(
				string $id,
				mixed $value,
				int $ttl,
			): bool
			{
				// the race, landed exactly here
				$this->other?->delete('guard-race');
				
				return parent::storeValue($id, $value, $ttl);
			}
		};
		$reader->other = $writer;
		$writer->delete($key);
		
		$reader->get($key, queue: false);
		$written = $reader->set($key, 'stale', 60);
		$read = $this->newStore()->get($key, queue: false);
		$writer->delete($key);
		
		return $written === false && $read === null;
	}
	
	/**
	 * RULE: every delete leaves a fresh token - two in a row differ, so a
	 * mark that expired and came back can never repeat what a miss saw
	 */
	public function everyDeleteLeavesAFreshToken(): bool
	{
		$key = 'guard-token';
		$store = $this->newStore();
		$id = $store->prefix($key, $store->getGroup());
		
		$store->delete($key);
		$first = apcu_fetch($id . Store::EPOCH_SUFFIX);
		$store->delete($key);
		$second = apcu_fetch($id . Store::EPOCH_SUFFIX);
		
		return is_string($first) && is_string($second) && $first !== $second;
	}
}
