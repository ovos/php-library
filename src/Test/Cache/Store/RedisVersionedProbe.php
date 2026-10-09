<?php
declare(strict_types=1);

namespace Ovos\Test\Cache\Store;

use Closure;
use Ovos\Cache\Stale;
use Ovos\Cache\Store\RedisVersioned;
use Ovos\Cache\Versioned\Rules;
use Ovos\Cache\Versioned\SharedRules;
use Override;

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
	
	#[Override]
	protected function fetchRuleEntries(
		string $from,
	): array
	{
		$this->fetchedFrom[] = $from;
		
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
