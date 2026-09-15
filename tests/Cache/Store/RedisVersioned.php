<?php
declare(strict_types=1);

namespace Tests\Cache\Store;

use Ovos\Cache\Store\RedisVersioned as Store;
use Ovos\Test;
use Ovos\Test\Internal;
use Ovos\Test\Cache\Store\TraitRedis;
use Override;

/**
 * RedisVersioned
 *
 * The rule based (logical) tag invalidation on a standalone Redis -
 * invalidations append rules instead of deleting items, reads evaluate
 * the rules server side in one Lua call.
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class RedisVersioned extends Test
{
	use TraitRedis;
	
	public const string KEY_ITEM = 'item';
	
	protected ?Store $store = null;
	
	public function __construct()
	{
		$this->store = $this->getStore(Store::class);
	}
	
	public function delete(): bool
	{
		$this->store->set(self::KEY_ITEM, 'test');
		$this->store->delete(self::KEY_ITEM);
		
		$exists = $this->store->get(self::KEY_ITEM, queue: false);
		
		return $exists === null;
	}
	
	public function storeArray(): bool
	{
		$array = [
			'stored' => true
		];
		
		$this->store->set(self::KEY_ITEM, $array);
		$array = $this->store->get(self::KEY_ITEM, queue: false);
		
		try
		{
			return $array['stored'] === true;
		}
		finally
		{
			$this->store->delete(self::KEY_ITEM);
		}
	}
	
	public function invalidateTags(): bool
	{
		$tags = ['tag1', 'tag2'];
		
		$this->store->set(self::KEY_ITEM, 'test', tags: $tags);
		$this->store->invalidateTags([$tags[0]]);
		
		$result = $this->store->get(self::KEY_ITEM, queue: false);
		
		try
		{
			return $result === null;
		}
		finally
		{
			$this->store->delete(self::KEY_ITEM);
		}
	}
	
	public function invalidateTagsMatchingAll(): bool
	{
		$this->store->set('item1', 'test', tags: ['tag1', 'tag2']);
		$this->store->set('item2', 'test', tags: ['tag1']);
		
		$this->store->invalidateTags(['tag1', 'tag2'], Store::MATCHING_ALL);
		
		$item1 = $this->store->get('item1', queue: false);
		$item2 = $this->store->get('item2', queue: false);
		
		try
		{
			// only the item having all the tags is invalidated
			return $item1 === null
				&& $item2 === 'test';
		}
		finally
		{
			$this->store->delete('item1');
			$this->store->delete('item2');
		}
	}
	
	/**
	 * An item written after the invalidation must survive it
	 * (the rules only match items older than themselves)
	 */
	public function invalidateThenSet(): bool
	{
		$tags = ['tag1'];
		
		$this->store->invalidateTags($tags);
		$this->store->set(self::KEY_ITEM, 'test', tags: $tags);
		
		$result = $this->store->get(self::KEY_ITEM, queue: false);
		
		try
		{
			return $result === 'test';
		}
		finally
		{
			$this->store->delete(self::KEY_ITEM);
		}
	}
	
	public function clear(): bool
	{
		$array = [
			'stored' => true
		];
		
		$this->store->set(self::KEY_ITEM, $array);
		$this->store->clear();
		$result = $this->store->get(self::KEY_ITEM, queue: false);
		
		return $result === null;
	}
	
	/**
	 * The rules stream is the only record that an invalidation happened: it
	 * must carry no TTL, or a volatile-* eviction policy could pick it
	 */
	public function rulesKeyIsNotVolatile(): bool
	{
		$client = $this->store->getClient();
		$rulesKey = $this->store->getRulesKey();
		
		$this->store->invalidateTags(['tag1']);
		
		if($client->pttl($rulesKey) !== -1)
		{
			return false;
		}
		
		// a stream left behind by an earlier version, which gave it a TTL
		$client->pexpire($rulesKey, 60000);
		$this->store->invalidateTags(['tag1']);
		
		return $client->pttl($rulesKey) === -1;
	}
	
	/**
	 * An invalidated item must not come back when the rules stream is lost
	 * (evicted, deleted, gone with its slot) before anyone read the item
	 */
	public function lostRulesDoNotResurrectAnItem(): bool
	{
		$tags = ['tag1'];
		
		// a rule before the item, so the item carries a real watermark
		$this->store->invalidateTags(['earlier']);
		$this->store->set(self::KEY_ITEM, 'test', tags: $tags);
		$this->store->invalidateTags($tags);
		
		$this->store->getClient()
			->del($this->store->getRulesKey());
		
		$result = $this->store->get(self::KEY_ITEM, queue: false);
		
		try
		{
			return $result === null;
		}
		finally
		{
			$this->store->delete(self::KEY_ITEM);
		}
	}
	
	/**
	 * The same, when another invalidation rebuilt the stream in between: the
	 * head is fresh again, and the rule that staled the item is nowhere in it
	 */
	public function lostRulesDoNotResurrectAnItemAfterAnotherRule(): bool
	{
		$tags = ['tag1'];
		
		$this->store->invalidateTags(['earlier']);
		$this->store->set(self::KEY_ITEM, 'test', tags: $tags);
		$this->store->invalidateTags($tags);
		
		$this->store->getClient()
			->del($this->store->getRulesKey());
		
		// a reborn stream takes its ids from the server clock, so a host fast
		// enough to run all of this inside one millisecond opens it on the id
		// the item already carries - and an opening rule that is not newer
		// than the stamp says nothing was lost. Let the clock move first
		usleep(2000);
		
		$this->store->invalidateTags(['unrelated']);
		
		$result = $this->store->get(self::KEY_ITEM, queue: false);
		
		try
		{
			return $result === null;
		}
		finally
		{
			$this->store->delete(self::KEY_ITEM);
		}
	}
	
	/**
	 * An item written after the loss has seen no rule: the rebuilt stream
	 * must not be held against it
	 */
	public function itemWrittenAfterLostRulesIsServed(): bool
	{
		$this->store->invalidateTags(['earlier']);
		$this->store->getClient()
			->del($this->store->getRulesKey());
		
		$this->store->set(self::KEY_ITEM, 'test', tags: ['tag1']);
		$this->store->invalidateTags(['unrelated']);
		
		$result = $this->store->get(self::KEY_ITEM, queue: false);
		
		try
		{
			return $result === 'test';
		}
		finally
		{
			$this->store->delete(self::KEY_ITEM);
		}
	}
	
	public function clearPhysical(): bool
	{
		$this->store->set(self::KEY_ITEM, 'test');
		$this->store->clearPhysical();
		
		// physically gone, not just logically stale
		$id = $this->store->prefix(self::KEY_ITEM, $this->store->getType());
		$exists = $this->store->getClient()
			->exists($id);
		
		return $exists === 0;
	}
	
	/**
	 * Called by the runner after each test method
	 */
	#[Internal]
	#[Override]
	public function finalize(): void
	{
		$this->store->clearPhysical();
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
