<?php
declare(strict_types=1);

namespace Tests\Cache\Store;

use Ovos\ArrayObject;
use Ovos\Cache\Store\RedisVersioned;
use Ovos\Cache\Versioned\Rules;
use Ovos\Cache\Versioned\SharedRules;
use Ovos\Connection\Redis as Connection;
use Ovos\Test;
use Ovos\Test\Exception\SkipException;
use Redis;

use function bin2hex;
use function count;
use function microtime;
use function random_bytes;
use function str_ends_with;

/**
 * Scope
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Scope extends Test
{
	public function changingGroupLoadsItsOwnRules(): bool
	{
		$store = $this->store();
		$first = $store->rules();
		$store->setGroup('a');
		if($store->rules() !== $first)
		{
			return false;
		}
		
		$store->setGroup('b');
		$second = $store->rules();
		$store->setGroup('a');
		$third = $store->rules();
		
		return $first->isStale(['alpha'], '0-0')
			&& $second->isStale(['beta'], '0-0')
			&& $second->isStale(['alpha'], '0-0') === false
			&& $third->isStale(['alpha'], '0-0')
			&& count($store->fetched) === 3;
	}
	
	public function changingGroupPreservesOtherGroupsSharedRules(): bool
	{
		if(SharedRules::isAvailable() === false)
		{
			throw new SkipException('APCu is required.');
		}
		
		$store = $this->store(true);
		$first = $store->shared();
		$second = null;
		try
		{
			$store->rules();
			$store->setGroup('b');
			$second = $store->shared();
			$rules = $store->rules();
			$store->setGroup('a');
			$returned = $store->rules();
			
			return $first->getKey() !== $second->getKey()
				&& $first->load(2592000000) !== null
				&& $second->load(2592000000) !== null
				&& $rules->isStale(['beta'], '0-0')
				&& $rules->isStale(['alpha'], '0-0') === false
				&& $returned->isStale(['alpha'], '0-0')
				&& count($store->fetched) === 2;
		}
		finally
		{
			$first->forget();
			$second?->forget();
		}
	}
	
	public function connectionsBelongToEachStore(): bool
	{
		$first = $this->store();
		$second = $this->store();
		
		return $first->getClient() !== $second->getClient()
			&& $first->getFunctions()->getClient() === $first->getClient()
			&& $second->getFunctions()->getClient() === $second->getClient()
			&& $first->getMemoLock()->getClient() === $first->getClient()
			&& $second->getMemoLock()->getClient() === $second->getClient()
			&& $first->getMemoLock()->getQueueClient() === $first->getQueueConnection()->getClient();
	}
	
	protected function store(
		bool $shared = false,
	): ScopeStore
	{
		return new ScopeStore(
			new ScopeConnection,
			new ScopeConnection,
			'scope_' . bin2hex(random_bytes(8)),
			new ArrayObject(['store_options' => ['rules_shared_cache' => $shared]]),
			'a',
		);
	}
}

class ScopeConnection extends Connection
{
	public function __construct()
	{
		parent::__construct(new ArrayObject);
		$this->client = new Redis;
	}
	
	public function getClient(): ?Redis
	{
		return $this->client;
	}
}

class ScopeStore extends RedisVersioned
{
	public array $fetched = [];
	
	protected function fetchRuleEntries(
		string $from,
	): array
	{
		$this->fetched[] = $this->getRulesKey();
		$id = (string)(int)(microtime(true) * 1000) . '-0';
		
		return [$id => [
			'mode' => 'any',
			'tags' => str_ends_with($this->getGroup(), ':a') ? 'alpha' : 'beta',
			'first' => '1',
		]];
	}
	
	public function rules(): Rules
	{
		return $this->getRules();
	}
	
	public function shared(): ?SharedRules
	{
		return $this->getSharedRules();
	}
}
