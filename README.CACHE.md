# Cache Stores (APCu, Redis, Redisearch)

This library provides three cache stores with a shared API. All stores support
`get()` with an optional resolver callback and integrate MemoLock for stampede
protection.

Access stores via the cache service (`php-library/src/Service/Cache.php`) so
they are created with the correct config and connections.

## Stores and differences

### APCu (`Ovos\Cache\Store\Apcu`)
- In-process memory cache (per PHP worker).
- Fastest, but not shared across servers or processes.
- No tags support.
- Best for local, short-lived data.

### Redis (`Ovos\Cache\Store\Redis`)
- Distributed cache shared across workers/servers.
- Supports tags with `getTags()`, `invalidateTags()`, and `getAllTags()`.
- Uses Redis Pub/Sub for MemoLock queueing, so it needs a separate queue
  connection.

### Redisearch (`Ovos\Cache\Store\Redisearch`)
- Distributed cache with tag invalidation powered by RediSearch.
- Uses a RediSearch TAG index for fast tag-based invalidation.
- Requires the RediSearch module and `MAXSEARCHRESULTS` set to `-1`.
- Supports tags in `set()` and `invalidateTags()`, but does not expose
  `getTags()` or `getAllTags()`.

## Common workflow (resolver)

```php
use Ovos\Service\Cache;

$cacheService = $container->get(Cache::class);
$store = $cacheService->getStore(); // persistent store (Redis or Redisearch)

$value = $store->get(
    'key',
    resolver: fn() => ['stored' => true],
    ttl: 30,
);
```

## Traditional workflow (get/set)

```php
use Ovos\Service\Cache;

$cacheService = $container->get(Cache::class);
$store = $cacheService->getStore();

if($store->get('key') === null)
{
    $store->set('key', ['stored' => true], ttl: 30);
}
```

## Access via getPersistent/getPerishable

```php
use Ovos\Service\Cache;

$cacheService = $container->get(Cache::class);

$persistentStore = $cacheService->getPersistent()->getStore();
$perishableStore = $cacheService->getPerishable()->getStore();

$persistentStore->set('key', 'value', ttl: 300);
$perishableStore->set('key', 'value', ttl: 30);
```

```php
use Ovos\Service\Cache;

$cacheService = $container->get(Cache::class);

$persistentQueue = $cacheService->getPersistent()->getQueue();
$perishableQueue = $cacheService->getPerishable()->getQueue();

$persistentQueue->lockAndQueue('queue-key', resolver: fn() => true);
$persistentQueue->releaseActiveLock('queue-key');

$perishableQueue->lockAndQueue('queue-key', resolver: fn() => true);
$perishableQueue->releaseActiveLock('queue-key');
```

## APCu example

```php
$cacheService = $container->get(Cache::class);
$store = $cacheService->getStore(persistent: false);

$value = $store->get(
    'key',
    resolver: fn() => 'value',
    ttl: 30,
);
```

## Redis example (tags)

```php
$value = $store->get(
    'key',
    resolver: fn() => 'value',
    ttl: 30,
    tags: ['tag1', 'tag2'],
);

$store->invalidateTags(['tag1']);
```

## Redis example (modify tags in resolver)

```php
$value = $store->get(
    'key',
    resolver: function($store, $key, &$ttl, &$tags) use ($value)
    {
        $tags[] = 'tag3';
        return $value;
    },
    ttl: 30,
    tags: ['tag1', 'tag2'],
);
```

## Redisearch example

```php
$value = $store->get(
    'key',
    resolver: fn() => 'value',
    ttl: 30,
    tags: ['tag1', 'tag2'],
);

$store->invalidateTags(['tag1', 'tag2']);
```

## Selecting Redis vs Redisearch

The persistent store class is selected in config:

```php
$config = new ArrayObject([
    'persistent' => [
        'store' => 'Redis', // or 'Redisearch'
    ],
]);
```

With this setting, `$cacheService->getStore()` returns either
`Ovos\Cache\Store\Redis` or `Ovos\Cache\Store\Redisearch`.

## Operational notes

- Redis and Redisearch require two Redis connections: one for data and one for
  MemoLock queueing (Pub/Sub) - if queueing is enabled.
- For Redisearch, ensure RediSearch is enabled and set
  `MAXSEARCHRESULTS` to `-1` (the store sets this during index creation).
