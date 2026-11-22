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
	
	/** run when the store logs something, as a logger would - a test reads the cache from there */
	public ?Closure $onLog = null;
	
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
	
	/** run before the prune drops what one step found outdated - a test writes an item again meanwhile */
	public ?Closure $beforePruneDrops = null;
	
	/** run before each read of the rules stream - a test makes the read fail */
	public ?Closure $beforeRuleFetch = null;
	
	/** the server's time cannot be read (see readServerTimeMs()) */
	public bool $timeUnreadable = false;
	
	/** how often the server's time was asked for */
	public int $serverTimeReads = 0;
	
	/** the server's clock reads that much later, as after a step or a failover to a node ahead */
	public int $serverAheadMs = 0;
	
	/**
	 * Called once, when a read starts to wait for another worker's reload: what that worker does meanwhile
	 */
	public ?Closure $whileAwaitingReload = null;
	
	/** How many times a read waited for another worker's reload */
	public int $awaited = 0;
	
	/**
	 * The function names each sendAll() sent in one round trip
	 *
	 * @var list<list<string>>
	 */
	public array $sentTogether = [];
	
	/** the releases its MemoLock sent on their own, not with a write */
	public int $releasesAlone = 0;
	
	/**
	 * Builds the shared set in another medium than APCu - called with the
	 * rules key and the identity, it answers a SharedRulesProbe (see
	 * SharedRulesInFiles)
	 */
	public ?Closure $sharedRulesFactory = null;
	
	/** the times it shared the set its own rules went into */
	public int $shares = 0;
	
	/** run at each of those shares - a test counts them past the instance */
	public ?Closure $onShare = null;
	
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
	protected function awaitReload(
		SharedRules $shared,
		float $untilMs,
		int $holder,
	): bool
	{
		$this->awaited++;
		if(($meanwhile = $this->whileAwaitingReload) !== null)
		{
			$this->whileAwaitingReload = null;
			$meanwhile();
		}
		
		return parent::awaitReload($shared, $untilMs, $holder);
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
	
	/**
	 * The store's own log() with a logger behind it ($onLog): the connection has none in the tests
	 */
	#[Override]
	protected function logNow(
		...$event,
	): static
	{
		if($this->onLog !== null)
		{
			($this->onLog)();
		}
		
		return parent::logNow(...$event);
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
	protected function pruneDrops(
		array $drops,
	): int
	{
		if($drops !== []
			&& $this->beforePruneDrops !== null)
		{
			($this->beforePruneDrops)();
		}
		
		return parent::pruneDrops($drops);
	}
	
	#[Override]
	protected function softly(
		array $item,
		Rules $rules,
	): Stale|false|null
	{
		$this->softlyCalls++;
		
		return parent::softly($item, $rules);
	}
	
	#[Override]
	protected function readServerTimeMs(): ?int
	{
		$this->serverTimeReads++;
		
		if($this->timeUnreadable
			|| ($serverMs = parent::readServerTimeMs()) === null)
		{
			return null;
		}
		
		return $serverMs + $this->serverAheadMs;
	}
	
	/**
	 * Makes the kept reading of the server's time that much older, as if the process had run on meanwhile
	 */
	public function ageServerTime(
		int $ms,
	): void
	{
		$this->serverTimeReadAtMs-= $ms;
	}
	
	public function rules(): Rules
	{
		return $this->getRules();
	}
	
	/**
	 * The shared set, a SharedRulesProbe (see newSharedRules())
	 */
	public function sharedRules(): ?SharedRulesProbe
	{
		$shared = $this->getSharedRules();
		
		return $shared instanceof SharedRulesProbe
			? $shared
			: null;
	}
	
	/**
	 * The rule set held and its verdict, as readRules() takes them for a read
	 *
	 * @return array{0: ?Rules, 1: bool}
	 */
	public function readVerdict(): array
	{
		return $this->readRules();
	}
	
	#[Override]
	protected function sharesRules(): bool
	{
		return $this->sharedRulesFactory !== null
			|| parent::sharesRules();
	}
	
	#[Override]
	protected function newSharedRules(): SharedRules
	{
		return $this->sharedRulesFactory !== null
			? ($this->sharedRulesFactory)($this->getRulesKey(), $this->sharedRulesIdentity())
			: new SharedRulesProbe($this->getRulesKey(), $this->sharedRulesIdentity());
	}
	
	#[Override]
	protected function shareHeldRules(): void
	{
		$this->shares++;
		if($this->onShare !== null)
		{
			($this->onShare)();
		}
		
		parent::shareHeldRules();
	}
}
