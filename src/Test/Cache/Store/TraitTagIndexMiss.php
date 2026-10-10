<?php
declare(strict_types=1);

namespace Ovos\Test\Cache\Store;

use Ovos\Cache\MemoLock\Redis as MemoLock;
use Ovos\Cache\Store\KeyValue\Redis as KeyValueRedis;
use Ovos\Cache\Store\KeyValue\TagIndex;

use function array_keys;
use function bin2hex;
use function count;
use function random_bytes;
use function str_ends_with;

/**
 * TraitTagIndexMiss
 *
 * A tag-index store's miss and tag invalidation (Store\Redis, Redisearch):
 * the lock, the second look and the miss's stamp in one round trip, the
 * write with the lock's release in another; a tag invalidation stamps the
 * tags and removes the items, no tombstone each - and a miss the stamps
 * cannot order is refused.
 *
 * The using class provides guardStore() (TraitInvalidationGuard) and
 * countingStore()
 *
 * @author Marcin Gil <mg@ovos.at>
 */
trait TraitTagIndexMiss
{
	/**
	 * A fresh store of the class under test that counts (TagIndexCounting)
	 */
	abstract protected function countingStore(): TagIndex;
	
	/**
	 * RULE: a miss takes the lock with its second look and its stamp - SET
	 * NX, the HMGET and TIME, pipelined - and the value it computed goes out
	 * with the lock's release: three round trips (the read, the lock, the
	 * write), not six
	 */
	public function aMissTakesTheLockWithItsLookAndStampAndReleasesItWithTheWrite(): bool
	{
		$store = $this->countingStore();
		$key = 'tag-index-miss-' . bin2hex(random_bytes(4));
		$lockKey = $store->getMemoLock()
			->getPrefixer()
			->prefix(MemoLock::TYPE_LOCK, $store->itemId($key));
		
		try
		{
			$value = $store->get($key, fn(): string => 'computed', 60, ['tag-index-miss']);
			$sent = $store->sentTogether;
			
			return $value === 'computed'
				&& $store->lookedWithLock === 1
				&& $store->serverTimes === 0
				&& count($sent) === 1
				&& count($sent[0]) === 2
				// the write itself landed in the pipeline, not through call() after it
				&& ($store->repliesTogether[0][0] ?? null) === 1
				&& str_ends_with($sent[0][1], 'memolock_release_lock_and_publish')
				&& $store->getClient()->exists($lockKey) === 0
				&& $this->guardStore()->get($key, queue: false) === 'computed';
		}
		finally
		{
			$store->delete($key);
		}
	}
	
	/**
	 * RULE: a value another process wrote between this one's miss and its
	 * lock is found by the look made with the lock: nothing is computed
	 */
	public function aValueLandingBeforeTheLockIsFoundByTheLookMadeWithIt(): bool
	{
		$store = $this->countingStore();
		$key = 'tag-index-landed-' . bin2hex(random_bytes(4));
		$computed = false;
		$store->beforeLock = function() use ($key): void
		{
			$this->guardStore()->set($key, 'landed', 60, ['tag-index-miss']);
		};
		
		try
		{
			$value = $store->get($key, function() use (&$computed): string
			{
				$computed = true;
				
				return 'computed';
			}, 60, ['tag-index-miss']);
			
			return $value === 'landed'
				&& $computed === false
				&& $store->lookedWithLock === 1;
		}
		finally
		{
			$store->delete($key);
		}
	}
	
	/**
	 * RULE: a tag invalidation removes the items it reaches, no tombstone each
	 * - the tag's stamp refuses a value computed for them meanwhile; a
	 * delete() still leaves one (no stamp covers a single key)
	 */
	public function aTagInvalidationLeavesNoTombstone(): bool
	{
		$store = $this->guardStore();
		$key = 'tag-index-tomb-' . bin2hex(random_bytes(4));
		$tag = 'tag-index-tomb-' . bin2hex(random_bytes(4));
		$id = $store->itemId($key);
		$client = $store->getClient();
		$store->set($key, 'value', 60, [$tag]);
		
		$this->guardStore()->invalidateTags([$tag]);
		$gone = (int)$client->exists($id) === 0;
		$store->set($key, 'value', 60, [$tag]);
		$this->guardStore()->delete($key);
		$tombstone = $client->hGetAll($id);
		$client->del($id);
		
		return $gone
			&& array_keys($tombstone) === [KeyValueRedis::KEY_EPOCH];
	}
	
	/**
	 * RULE: a miss without its stamp - read with peek(), never stamped - is
	 * refused once a tag of its write was invalidated within the window: no
	 * tombstone marks the item, and a stamp cannot order a miss that carries
	 * no time
	 */
	public function anUnstampedWriteAfterATagInvalidationIsRefused(): bool
	{
		$reader = $this->guardStore();
		$key = 'tag-index-unstamped-' . bin2hex(random_bytes(4));
		$tag = 'tag-index-unstamped-' . bin2hex(random_bytes(4));
		
		$missed = $reader->peek($key) === null;
		$this->guardStore()->invalidateTags([$tag]);
		$written = $reader->set($key, 'stale', 60, [$tag]);
		$read = $this->guardStore()->get($key, queue: false);
		$reader->delete($key);
		
		return $missed
			&& $written === false
			&& $read === null;
	}
}
