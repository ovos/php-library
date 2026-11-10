<?php
declare(strict_types=1);

namespace Ovos\Test\Cache\Store;

use Ovos\Cache\MemoLock\Redis as MemoLock;
use Ovos\Cache\Store\KeyValue\Redis as KeyValueRedis;
use Ovos\Cache\Store\RedisVersioned;
use Redis as RedisClient;
use RedisCluster as RedisClusterClient;

use function array_keys;
use function bin2hex;
use function count;
use function random_bytes;

/**
 * TraitPrune
 *
 * The versioned stores' prune (RedisVersioned::collectGarbage()): it drops what a read would drop and
 * nothing else. Each rule works in a group of its own, so the walk sees only what the rule wrote there.
 *
 * The using class provides guardStore()
 *
 * @author Marcin Gil <mg@ovos.at>
 */
trait TraitPrune
{
	/**
	 * How many outdated items the rule spreads over the keyspace (on a cluster: over every master)
	 */
	protected const int PRUNE_OUTDATED = 30;
	
	/**
	 * A fresh instance of the store under test - another process
	 */
	abstract protected function guardStore(
		array $storeOptions = [],
	): KeyValueRedis;
	
	/**
	 * RULE: the prune drops the items the rules made outdated (one written through, which keeps its token for
	 * the window, and ones the store computed, which go whole) and answers how many. It leaves a fresh item,
	 * a tombstone, a soft value inside its stale time, the rules stream, another group's outdated item, and
	 * every key of another shape under the item prefix: a plain hash, a hash with the item fields and one
	 * more, a hash whose mark is no rule id, a string, a list, a lock. On a cluster the outdated items sit on
	 * every master, and every one is dropped.
	 */
	public function thePruneDropsOnlyOutdatedItems(): bool
	{
		$group = 'prune-' . bin2hex(random_bytes(4));
		$store = $this->pruneStore($group);
		$sibling = $this->pruneStore($group . '-sibling');
		$client = $store->getClient();
		$window = $store->getInvalidationWindowMs();
		
		try
		{
			// outdated: one written through (it carries a token; it would live an hour, so its drop must cut the
			// token's life to the window), the rest computed by the store (none)
			$store->set('through', 'old', 3600, ['gone']);
			$outdated = ['through'];
			for($index = 0; $index < static::PRUNE_OUTDATED; $index++)
			{
				$store->get('computed-' . $index, fn(): string => 'old', 60, ['gone']);
				$outdated[] = 'computed-' . $index;
			}
			// kept: a fresh item, a tombstone, a soft value a soft rule reaches inside its stale time
			$store->set('fresh', 'kept', 60, ['kept']);
			$store->delete('tomb');
			$store->get('soft', fn(): string => 'aged', 60, ['softened'], stale: 60, soft: true);
			// another group's item, outdated by its own rules
			$sibling->set('far', 'old', 60, ['gone']);
			$sibling->invalidateTags(['gone']);
			$store->invalidateTags(['gone']);
			$store->invalidateTags(['softened']);
			
			// keys of another shape under the item prefix, each with a tag and a mark the rules make outdated
			$foreign = [
				'hash' => fn(string $id): mixed => $client->hSet($id, 'count', '5'),
				'extra' => fn(string $id): mixed => $client->hMSet($id, [
					KeyValueRedis::KEY_DATA => 'x',
					KeyValueRedis::KEY_TAGS => 'gone',
					RedisVersioned::KEY_MARK => '1-0',
					'owner' => 'app',
				]),
				'mark' => fn(string $id): mixed => $client->hMSet($id, [
					KeyValueRedis::KEY_DATA => 'x',
					KeyValueRedis::KEY_TAGS => 'gone',
					RedisVersioned::KEY_MARK => 'yesterday',
				]),
				'string' => fn(string $id): mixed => $client->set($id, 'v'),
				'list' => fn(string $id): mixed => $client->rPush($id, 'a', 'b'),
			];
			$before = [];
			foreach($foreign as $name => $seed)
			{
				$seed($store->itemId('foreign-' . $name));
				$before[$name] = $this->dumpKey($client, $store->itemId('foreign-' . $name));
			}
			$lockKey = $store->getMemoLock()
				->getPrefixer()
				->prefix(MemoLock::TYPE_LOCK, $store->itemId('fresh'));
			$client->set($lockKey, 'held');
			$tombstone = $client->hGetAll($store->itemId('tomb'));
			$rules = (int)$client->xLen($store->getRulesKey());
			$everyMaster = $this->outdatedOnEveryMaster($store);
			
			$pruned = $store->collectGarbage();
			$again = $store->collectGarbage();
			
			// the outdated items: the one written through keeps its token alone, for the window
			$throughId = $store->itemId('through');
			$throughTtl = (int)$client->pTtl($throughId);
			$dropped = array_keys((array)$client->hGetAll($throughId)) === [KeyValueRedis::KEY_EPOCH]
				&& $throughTtl > 0
				&& $throughTtl <= $window;
			foreach($outdated as $key)
			{
				if($key !== 'through'
					&& (int)$client->exists($store->itemId($key)) !== 0)
				{
					$dropped = false;
				}
			}
			
			// everything else is as it was
			$kept = $this->pruneStore($group)->get('fresh', queue: false) === 'kept'
				&& $client->hGetAll($store->itemId('tomb')) === $tombstone
				&& (int)$client->hExists($store->itemId('soft'), KeyValueRedis::KEY_DATA) === 1
				&& (int)$client->hExists($sibling->itemId('far'), KeyValueRedis::KEY_DATA) === 1
				&& (int)$client->xLen($store->getRulesKey()) === $rules
				&& $client->get($lockKey) === 'held';
			foreach($foreign as $name => $seed)
			{
				if($this->dumpKey($client, $store->itemId('foreign-' . $name)) !== $before[$name])
				{
					$kept = false;
				}
			}
			
			return $pruned === count($outdated)
				&& $again === 0
				&& $dropped
				&& $kept
				&& $everyMaster;
		}
		finally
		{
			$store->clearPhysical();
			$sibling->clearPhysical();
		}
	}
	
	/**
	 * RULE: with no copy of the rules that may judge (the stream cannot be read), the prune answers false
	 * and drops nothing
	 */
	public function thePruneWithoutACopyOfTheRulesDropsNothing(): bool
	{
		$group = 'prune-' . bin2hex(random_bytes(4));
		$store = $this->pruneStore($group);
		$client = $store->getClient();
		$id = $store->itemId('old');
		
		try
		{
			$store->set('old', 'x', 60, ['gone']);
			$store->invalidateTags(['gone']);
			// the rules stream's key holds something else, and a new instance holds no copy
			$client->del($store->getRulesKey());
			$client->set($store->getRulesKey(), 'not a stream');
			
			$pruned = $this->pruneStore($group)
				->collectGarbage();
			
			return $pruned === false
				&& (int)$client->hExists($id, KeyValueRedis::KEY_DATA) === 1;
		}
		finally
		{
			$client->del($store->getRulesKey());
			$store->clearPhysical();
		}
	}
	
	/**
	 * A versioned store of its own group, reading its rules exactly
	 */
	protected function pruneStore(
		string $group,
	): RedisVersioned
	{
		$store = $this->guardStore();
		$store->setGroup($group);
		
		return $store;
	}
	
	/**
	 * What a key holds, whatever its type: compared before and after the prune
	 */
	protected function dumpKey(
		RedisClient|RedisClusterClient $client,
		string $id,
	): mixed
	{
		return [
			(int)$client->exists($id),
			$client->type($id),
			match($client->type($id))
			{
				RedisClient::REDIS_HASH => $client->hGetAll($id),
				RedisClient::REDIS_LIST => $client->lRange($id, 0, -1),
				RedisClient::REDIS_STRING => $client->get($id),
				default => null,
			},
		];
	}
	
	/**
	 * On a cluster: whether the computed outdated items sit on every master, so a walk that missed one would
	 * leave some behind. Always true on a standalone server.
	 */
	protected function outdatedOnEveryMaster(
		RedisVersioned $store,
	): bool
	{
		$client = $store->getClient();
		if($client instanceof RedisClusterClient === false)
		{
			return true;
		}
		
		$prefix = $store->itemId('');
		foreach($client->_masters() as $master)
		{
			$found = 0;
			$cursor = null;
			do
			{
				$found+= count((array)$client->scan($cursor, $master, $prefix . 'computed-*', 1000));
			}
			while((int)$cursor !== 0);
			
			if($found === 0)
			{
				return false;
			}
		}
		
		return true;
	}
}
