<?php
declare(strict_types=1);

namespace Tests\Cache;

use Ovos\Cache\Store\Apcu;
use Ovos\Cache\Store\Redis;
use Ovos\Cache\Store\Redisearch;
use Ovos\Cache\Store\RedisClusterVersioned;
use Ovos\Cache\Store\RedisVersioned;
use Ovos\Test;
use ReflectionMethod;
use ReflectionNamedType;

use function array_map;
use function implode;
use function in_array;

/**
 * The store API every store shares - one get() an override copies once and
 * a new option never changes: get(string $key, ?Closure $resolver,
 * int $ttl, array $tags, mixed ...$options); setFromResolver() the same with
 * the options read (?Policy); the lock TTL in milliseconds everywhere
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class StoreSignature extends Test
{
	protected const array STORES = [Apcu::class, Redis::class, Redisearch::class, RedisVersioned::class, RedisClusterVersioned::class];
	
	public function everyStoreHasTheOneGet(): bool
	{
		foreach(static::STORES as $store)
		{
			if($this->signature($store, 'get') !== 'string $key, ?Closure $resolver, int $ttl, array $tags, mixed ...$options')
			{
				return false;
			}
		}
		
		return true;
	}
	
	public function everyStoreHasTheOneSetFromResolver(): bool
	{
		foreach(static::STORES as $store)
		{
			if($this->signature($store, 'setFromResolver') !== 'string $key, ?Closure $resolver, int $ttl, array $tags, ?Ovos\Cache\Policy $policy')
			{
				return false;
			}
		}
		
		return true;
	}
	
	public function theLockTtlIsInMillisecondsOnEveryStore(): bool
	{
		foreach(static::STORES as $store)
		{
			$names = array_map(static fn($parameter): string => $parameter->getName(),
				(new ReflectionMethod($store, 'lockAndQueue'))->getParameters());
			if(in_array('queueLockTtlMs', $names, true) === false)
			{
				return false;
			}
		}
		
		return true;
	}
	
	/**
	 * The method's parameters as one line: type, variadic, name
	 */
	protected function signature(
		string $class,
		string $method,
	): string
	{
		return implode(', ', array_map(static function($parameter): string
		{
			$type = $parameter->getType();
			$name = $type instanceof ReflectionNamedType
				? ($type->allowsNull() && $type->getName() !== 'mixed' ? '?' : '') . $type->getName()
				: (string)$type;
			
			return $name . ' ' . ($parameter->isVariadic() ? '...' : '') . '$' . $parameter->getName();
		}, (new ReflectionMethod($class, $method))->getParameters()));
	}
}
