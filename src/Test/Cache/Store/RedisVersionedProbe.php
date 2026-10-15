<?php
declare(strict_types=1);

namespace Ovos\Test\Cache\Store;

use Closure;
use Ovos\Cache\MemoLock\Redis as MemoLock;
use Ovos\Cache\Stale;
use Ovos\Cache\Store\RedisVersioned;
use Ovos\Cache\Versioned\Rules;
use Ovos\Cache\Versioned\SharedRules;
use Override;

use function array_column;
use function array_key_exists;

/**
 * RedisVersionedProbe
 *
 * The versioned store with its rule plumbing exposed, for tests that need
 * to see what the store fetched rather than only what it answered: every
 * XRANGE is recorded with the id it started from, and the held set and the
 * shared cache handle can be read.
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class RedisVersionedProbe extends RedisVersioned
{
	/**
	 * The ids the rule fetches started from, in order (Rules::NONE = the beginning)
	 *
	 * @var string[]
	 */
	public array $fetchedFrom = [];
	
	/**
	 * Called with the shared set before each adoption of it - a test plants
	 * what a leader would share meanwhile
	 */
	public ?Closure $beforeAdopt = null;
	
	/** what the store logged */
	public array $logged = [];
	
	/** the stale items it dropped */
	public int $dropped = 0;
	
	/** the invalidated items it decoded for the soft verdict */
	public int $softlyCalls = 0;
	
	/** its fetch() calls - the second look made with the lock is none */
	public int $fetches = 0;
	
	/** the locks taken with their second look, in one round trip */
	public int $lookedWithLock = 0;
	
	/** run right before the lock with its look - a test lands a value meanwhile */
	public ?Closure $beforeLock = null;
	
	/** run before each read of the rules stream - a test makes the read fail */
	public ?Closure $beforeRuleFetch = null;
	
	/**
	 * The function names each sendAll() sent in one round trip
	 *
	 * @var list<list<string>>
	 */
	public array $sentTogether = [];
	
	/** the releases its MemoLock sent on their own, not with a write */
	public int $releasesAlone = 0;
	
	#[Override]
	protected function fetch(
		string $id,
	): mixed
	{
		$this->fetches++;
		
		return parent::fetch($id);
	}
	
	#[Override]
	protected function lockAndLook(
		string $id,
	): ?Closure
	{
		if(($lockAndLook = parent::lockAndLook($id)) === null)
		{
			return null;
		}
		
		return function(string $lockKey, string $lockValue, int $ttlMs) use ($lockAndLook): array
		{
			if($this->beforeLock !== null)
			{
				($this->beforeLock)();
			}
			$this->lookedWithLock++;
			
			return $lockAndLook($lockKey, $lockValue, $ttlMs);
		};
	}
	
	#[Override]
	protected function sendAll(
		array $calls,
	): ?array
	{
		$this->sentTogether[] = array_column($calls, 0);
		
		return parent::sendAll($calls);
	}
	
	/**
	 * Its MemoLock counts the releases it sends on their own
	 */
	#[Override]
	public function getMemoLock(): MemoLock
	{
		if($this->memoLock === null)
		{
			$memoLock = new class($this->connection, $this->queueConnection, $this->prefixer->getPrefix(), $this->config, $this) extends MemoLock
			{
				public ?Closure $onRelease = null;
				
				#[Override]
				public function releaseActiveLock(
					string $id,
				): bool
				{
					if($this->onRelease !== null
						&& array_key_exists($id, $this->queueLocks))
					{
						($this->onRelease)();
					}
					
					return parent::releaseActiveLock($id);
				}
			};
			$memoLock->onRelease = function(): void
			{
				$this->releasesAlone++;
			};
			$this->memoLock = $memoLock;
		}
		
		return $this->memoLock;
	}
	
	/**
	 * Loads the stream $count rules at a time (see rulesBatch)
	 */
	public function batchRulesBy(
		int $count,
	): static
	{
		$this->rulesBatch = $count;
		
		return $this;
	}
	
	#[Override]
	protected function fetchRuleEntries(
		string $from,
	): array
	{
		$this->fetchedFrom[] = $from;
		if($this->beforeRuleFetch !== null)
		{
			($this->beforeRuleFetch)();
		}
		
		return parent::fetchRuleEntries($from);
	}
	
	#[Override]
	protected function adoptSharedRules(
		SharedRules $shared,
	): bool
	{
		if($this->beforeAdopt !== null)
		{
			($this->beforeAdopt)($shared);
		}
		
		return parent::adoptSharedRules($shared);
	}
	
	#[Override]
	public function log(
		...$event,
	): static
	{
		$this->logged[] = $event;
		
		return parent::log(...$event);
	}
	
	#[Override]
	protected function dropStale(
		string $id,
		string $mark,
	): void
	{
		$this->dropped++;
		
		parent::dropStale($id, $mark);
	}
	
	#[Override]
	protected function softly(
		array $item,
	): ?Stale
	{
		$this->softlyCalls++;
		
		return parent::softly($item);
	}
	
	public function rules(): Rules
	{
		return $this->getRules();
	}
	
	public function sharedRules(): ?SharedRules
	{
		return $this->getSharedRules();
	}
}
