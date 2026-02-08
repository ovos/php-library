# MemoLock and Cache Stampede Protection

MemoLock is a queue-based stampede protection mechanism used by the cache
stores in this library. It prevents multiple requests from rebuilding the
same missing cache item at once by ensuring only one request holds the lock
while other contenders wait.

Two backends are supported:
- Redis: distributed lock plus Pub/Sub queue.
- APCu: in-process lock with a short randomized backoff.

This document describes how to use MemoLock through the cache stores and how
to use MemoLock as a standalone locking mechanism.

## References

1. https://blog.lucas-simon.com/how-i-took-down-my-site-and-fixed-it-with-memolock  
Implementation in TypeScript:
https://github.com/demipixel/redis-memolock-node/blob/master/src/index.ts
1. https://redis.io/blog/caches-promises-locks/
Implementation in Go:  
https://github.com/kristoff-it/redis-memolock/tree/master

## Cache stores and MemoLock

Cache stores integrate MemoLock automatically:
- `Ovos\Cache\Store\Redis` (supports tags)
- `Ovos\Cache\Store\Apcu` (local memory, no tags)

When using `get()` with a resolver, the store acquires the lock, calls the
resolver, and releases the lock as part of `set()`. You typically do not need
to call `releaseActiveLock()` yourself in this path.

### Common workflow (resolver)

The resolver-first approach keeps the locking and saving in one call.

```php
$value = $cache->get(
    'key',
    resolver: fn() => $expensiveCall(),
    ttl: 30, // override of the default cache TTL
);
```

### Traditional workflow (get/set)

If you prefer the traditional get/set flow, MemoLock still protects the window
between `get()` and `set()` (when queueing is enabled).

```php
if ($cache->get('key') === null)
{
    $value = $expensiveCall();
    $cache->set('key', $value, ttl: 30);
}
```

### Redis cache example (with tags)

```php
$value = $cache->get(
    'key',
    resolver: fn() => $expensiveCall(),
    ttl: 30,
    tags: ['tag1', 'tag2'],
);
```

### Resolver that modifies tags

The resolver can modify arguments passed to `get`.  
The first argument is the cache store object
or the memolock object when used as a standalone mechanism (outside of cache stores).

```php
$value = $cache->get(
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

### Per-call queue overrides

You can override queue usage for a single call
and skip queueing for a resolver (immediate set):

```php
$value = $cache->get(
    'key',
    resolver: fn() => $expensiveCall(),
    queue: false,
);
```

You can also override the lock TTL per call:

```php
$value = $cache->get(
    'key',
    resolver: fn() => $expensiveCall(),
    queueLockTtlMs: 5000, // Redis store
);
```

How to choose `queueLockTtlMs` (Redis):
- Set it to the expected maximum time between `get()` and `set()` for that key.
- Use a higher value for slower work (API calls, image processing, bulk IO).
- Too low means more contenders may time out and rebuild in parallel.
- Too high means more time before other contenders can take over if the worker dies.

For APCu, the argument is `queueLockTtlS` (seconds).

### Manual queue control with the cache store

If you want to control the lock manually:

```php
if($cache->get('key', queue: false) === null)
{
    $cache->lockAndQueue('key');
    $cache->set('key', $value, ttl: 30);
}
```

If you decide not to call `set()` (e.g., error path), release the lock:

```php
$cache->releaseActiveLock('key');
```

For long-running processes, extend the lock while you work:

```php
while($stillWorking)
{
    $cache->renewLock('key');
}
```

Force queueing for a single call even when queueing is disabled globally:

```php
$wasEnabled = $cache->isQueueEnabled();
$cache->setQueueEnabled(false);

$value = $cache->get(
    'key',
    resolver: fn() => $expensiveCall(),
    queue: true,
);

$cache->setQueueEnabled($wasEnabled);
```

Lock-only mode for a critical section.
It will never be executed in parallel (one at a time rate-limited).

```php
$cache->lockAndQueue('key');
try
{
    // critical section
}
finally
{
    $cache->releaseActiveLock('key');
}
```

## MemoLock configuration

MemoLock reads queue settings from the cache config. The keys differ between
Redis and APCu:

Redis queue config:

```php
$config = new ArrayObject([
    'queue' => [
        'enabled' => true,
        'lock_ttl_ms' => 2000,
        'wait_attempts' => 3,
        'debug' => [
            'enabled' => false,
            'timeouts' => false,
            'filename' => 'memolock_debug',
        ],
    ],
]);
```

APCu queue config:

```php
$config = new ArrayObject([
    'queue' => [
        'enabled' => true,
        'lock_ttl_s' => 1,
        'wait_timeout_s' => 2,
        'backoff_min_ms' => 5,
        'backoff_max_ms' => 25,
        'debug' => [
            'enabled' => false,
            'timeouts' => false,
            'filename' => 'memolock_debug',
        ],
    ],
]);
```

## MemoLock as a standalone mechanism

You can use MemoLock directly to protect any critical section. This is useful
when no cache item is involved.

Redis MemoLock:

```php
$id = 'thumbnails:' . $file;
$memoLock->lockAndQueue(
    $id,
    fetcher: function(?RedisMemoLock $memoLock = null) use ($file)
    {
        clearstatcache(true, $file);
        return file_exists($file) ? true : null;
    },
    resolver: function(?RedisMemoLock $memoLock = null) use ($file)
    {
        // generate the file
        // ...
        $memoLock?->debug('thumbnail created: ' . $file);
        return true;
    },
    queueLockTtlMs: 5000,
);

$memoLock->releaseActiveLock($id);
```

APCu MemoLock uses the same flow, but the lock TTL uses seconds:

```php
$memoLock->lockAndQueue(
    $id,
    fetcher: $fetcher,
    resolver: $resolver,
    queueLockTtlS: 2,
);
$memoLock->releaseActiveLock($id);
```
