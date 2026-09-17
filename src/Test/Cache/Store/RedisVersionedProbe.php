<?php
declare(strict_types=1);

namespace Ovos\Test\Cache\Store;

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
	
	#[Override]
	protected function fetchRuleEntries(
		string $from,
	): array
	{
		$this->fetchedFrom[] = $from;
		
		return parent::fetchRuleEntries($from);
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
