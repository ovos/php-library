<?php
declare(strict_types=1);

namespace Ovos\Test\Cache\Store;

use Ovos\Cache\Stale;
use Ovos\Cache\Store\KeyValue;
use Ovos\Cache\Store\KeyValue\Redis;
use Ovos\Cache\Store\KeyValue\Tags;
use RedisCluster;
use RuntimeException;

use function apcu_key_info;
use function ceil;
use function min;

/**
 * Inspect real cache expiry and age an existing test value without sleeping.
 * No clock or production cache behavior is replaced.
 */
trait TraitStoredTime
{
	/**
	 * Milliseconds until Redis data expires, or APCu's assigned TTL in ms.
	 * Redis may expire the data field before the key that retains its epoch.
	 * Negative values retain Redis's missing (-2) / persistent (-1) meaning.
	 */
	protected function storedTtlMs(KeyValue $store, string $key): int
	{
		$id = $store->itemId($key);
		if($store instanceof Redis)
		{
			$client = $store->getClient();
			$keyTtl = (int)$client->pTtl($id);
			$fields = $client instanceof RedisCluster
				? $client->rawCommand($id, 'HPTTL', $id, 'FIELDS', 1, Redis::KEY_DATA)
				: $client->rawCommand('HPTTL', $id, 'FIELDS', 1, Redis::KEY_DATA);
			$fieldTtl = (int)($fields[0] ?? -2);

			return $keyTtl === -1 ? $fieldTtl
				: ($fieldTtl === -1 ? $keyTtl : min($keyTtl, $fieldTtl));
		}

		$info = apcu_key_info($id);

		return $info === null ? -2 : ($info['ttl'] === 0 ? -1 : $info['ttl'] * 1000);
	}

	/**
	 * Age the envelope produced by the real writer, preserving its payload and
	 * windows. Callers supply the original tags and verify the original TTL
	 * separately when retention is part of the rule under test. Physical expiry
	 * and lock contention tests still use the real clock.
	 */
	protected function ageStoredValue(KeyValue $store, string $key, float $seconds, array $tags = []): void
	{
		$value = $store->peek($key);
		$ttl = $this->storedTtlMs($store, $key);
		if($value instanceof Stale === false || $ttl <= 0)
		{
			throw new RuntimeException('Expected a stored Stale value with a finite lifetime.');
		}

		$aged = new Stale($value->value, $value->freshUntil - $seconds,
			$value->staleFor, $value->soft, $value->errorFor);
		$secondsLeft = (int)ceil($ttl / 1000);
		$written = $store instanceof Tags
			? $store->set($key, $aged, $secondsLeft, $tags)
			: $store->set($key, $aged, $secondsLeft);
		if($written === false)
		{
			throw new RuntimeException('Could not age the stored test value.');
		}
	}
}
