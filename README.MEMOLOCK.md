# MemoLock - Cache Stampede Protection

MemoLock prevents **cache stampedes** - a critical production issue where many
concurrent requests try to rebuild the same expired cache key at the same time,
overwhelming the database.

See also: [README.CACHE.md](README.CACHE.md) for general cache usage.

## The problem: cache stampedes

Consider a popular cache key like "all job categories" that expires every 60
seconds. At the moment it expires:

**Without MemoLock:**
```
Request 1 → cache miss → queries database → rebuilds cache
Request 2 → cache miss → queries database → rebuilds cache  (wasted)
Request 3 → cache miss → queries database → rebuilds cache  (wasted)
Request 4 → cache miss → queries database → rebuilds cache  (wasted)
...50 more requests all hitting the database simultaneously
```

This causes a **thundering herd**: the database gets hammered with identical
queries, response times spike, and the system may become unresponsive.

**With MemoLock:**
```
Request 1 → cache miss → acquires lock → queries database → rebuilds cache → releases lock
Request 2 → cache miss → waits for lock → gets cached value from Request 1
Request 3 → cache miss → waits for lock → gets cached value from Request 1
Request 4 → cache miss → waits for lock → gets cached value from Request 1
...50 more requests all get the value without touching the database
```

Only one request does the work. Everyone else waits and benefits from the result.

## How it works

Two backends are supported:

| Backend | Lock mechanism | Wait mechanism | Scope |
|---------|---------------|----------------|-------|
| **Redis** | Distributed Redis lock | Pub/Sub subscription | All workers/servers |
| **APCu** | In-process lock | Randomized backoff polling | Single worker |

**Redis MemoLock** is ideal for production - it coordinates across all PHP workers
and servers using Redis Pub/Sub. When the lock holder calls `set()`, it publishes
a notification and all waiting requests receive the cached value simultaneously.

**Redis Cluster:** MemoLock works on a cluster store too - the lock routes to
the node owning the lock key and the release notification is broadcast
cluster-wide. The queue (Pub/Sub) connection must be a standalone `redis`
connection pointed at a node of **the same cluster** (a classic PUBLISH never
reaches a different Redis instance); see README.CACHE.md for the configuration.

**APCu MemoLock** is simpler - it uses a local lock with short randomized backoff
retries. Good for per-worker caches where distributed coordination isn't needed.

## Using MemoLock (you probably already are)

MemoLock is **integrated into the cache stores** and **enabled by default**. If
you use `get()` with a resolver, MemoLock is protecting you automatically:

```php
// MemoLock protects this automatically:
$value = $cache->get(
    'popular-key',
    resolver: fn() => $this->expensiveQuery(),
    ttl: 60,
);
```

The traditional get/set flow is also protected when queueing is enabled:

```php
// MemoLock also protects the window between get() and set():
if($cache->get('key') === null)
{
    $value = $this->expensiveQuery();
    $cache->set('key', $value, ttl: 60);
    // Lock is released automatically when set() completes
}
```

## Per-call overrides

### Skip queueing for a specific call

For non-critical keys where stampede isn't a concern:

```php
$value = $cache->get(
    'key',
    resolver: fn() => $this->cheapQuery(),
    queue: false,   // No lock, no waiting
);
```

### Custom lock TTL

For slow operations (API calls, image processing), increase the lock duration
so waiters don't give up too early:

```php
// Redis: lock TTL in milliseconds
$value = $cache->get(
    'external-api-result',
    resolver: fn() => $this->callSlowExternalAPI(),
    queueLockTtlMs: 10000,   // 10 seconds
);

// APCu: lock TTL in seconds
$value = $cache->get(
    'key',
    resolver: fn() => $this->computation(),
    queueLockTtlS: 5,
);
```

**How to choose the lock TTL:**
- Set it to the expected maximum time your resolver needs to complete.
- **Too low**: waiters time out and rebuild in parallel (defeats the purpose).
- **Too high**: if the lock holder dies, others wait longer before retaking the lock.
- Default: 2000ms for Redis, 1s for APCu - good for typical database queries.

## Manual lock control

For advanced scenarios where you need explicit control over the lock lifecycle.

### Lock, compute, set

```php
if($cache->get('key', queue: false) === null)
{
    $cache->lockAndQueue('key');         // Acquire lock, others start waiting
    $value = $this->expensiveWork();
    $cache->set('key', $value, ttl: 60); // Set value AND release lock
}
```

### Error handling - release without setting

If you acquire a lock but can't produce a value (e.g., an error), release
the lock so others can try:

```php
$cache->lockAndQueue('key');
try
{
    $value = $this->riskyOperation();
    $cache->set('key', $value, ttl: 60);
}
catch(Throwable $e)
{
    $cache->releaseActiveLock('key');   // Release lock without setting a value
    throw $e;
}
```

### Extend the lock for long-running work

If your computation takes longer than the lock TTL, renew the lock
periodically to prevent others from stealing it:

```php
$cache->lockAndQueue('key');
foreach($largeDataSet as $item)
{
    $this->processItem($item);
    $cache->renewLock('key');   // Reset the lock TTL
}
$cache->set('key', $result, ttl: 300);
```

### Critical section (lock-only, no cache)

Use MemoLock purely as a distributed mutex to prevent parallel execution:

```php
$cache->lockAndQueue('import:companies');
try
{
    // Only one worker can run this at a time
    $this->importCompanies();
}
finally
{
    $cache->releaseActiveLock('import:companies');
}
```

### Force queueing for a single call

Override the global queue setting for one call:

```php
$wasEnabled = $cache->isQueueEnabled();
$cache->setQueueEnabled(false);

$value = $cache->get(
    'key',
    resolver: fn() => $this->compute(),
    queue: true,   // Force queue even though globally disabled
);

$cache->setQueueEnabled($wasEnabled);
```

## Standalone MemoLock (without cache stores)

You can use MemoLock directly for non-cache scenarios like file generation
or resource provisioning.

### Redis MemoLock

```php
$id = 'thumbnails:' . $file;
$memoLock->lockAndQueue(
    $id,
    fetcher: function(?RedisMemoLock $memoLock = null) use ($file)
    {
        // Check if the resource already exists
        clearstatcache(true, $file);
        return file_exists($file) ? true : null;  // null = needs work
    },
    resolver: function(?RedisMemoLock $memoLock = null) use ($file)
    {
        // Generate the resource
        $this->generateThumbnail($file);
        $memoLock?->debug('thumbnail created: ' . $file);
        return true;
    },
    queueLockTtlMs: 5000,
);
$memoLock->releaseActiveLock($id);
```

### APCu MemoLock

Same pattern, but lock TTL uses seconds:

```php
$memoLock->lockAndQueue(
    $id,
    fetcher: $fetcher,
    resolver: $resolver,
    queueLockTtlS: 2,
);
$memoLock->releaseActiveLock($id);
```

## Configuration

MemoLock reads its settings from the cache config.

### Redis queue config

```yaml
persistent:
  queue:
    enabled: yes              # Enable/disable MemoLock for this tier
    connection: redis          # Redis connection for Pub/Sub (separate from data)
    lock_ttl_ms: 2000          # Lock duration in milliseconds
    wait_attempts: 3           # Number of Pub/Sub subscribe attempts
    debug:
      enabled: no              # Log MemoLock operations
      timeouts: no             # Log timeout events
      filename: memolock_debug # Log file name (in LOGS_DIR)
```

### APCu queue config

```yaml
perishable:
  queue:
    enabled: yes
    lock_ttl_s: 1              # Lock duration in seconds
    wait_timeout_s: 2          # Total max wait time
    backoff_min_ms: 5          # Minimum sleep between retries
    backoff_max_ms: 25         # Maximum sleep between retries
    debug:
      enabled: no
      timeouts: no
      filename: memolock_debug
```

## When to care about stampedes

**High risk** (always use MemoLock):
- Popular keys accessed by many concurrent users (homepage data, navigation)
- Expensive queries (JOINs, aggregations, full-text search)
- External API calls cached locally

**Low risk** (MemoLock still helps but less critical):
- Per-user data with few concurrent hits
- Very fast queries (simple primary key lookups)
- Rarely accessed pages

Since MemoLock is enabled by default and the overhead is minimal (one extra
Redis command per cache miss), there's no reason to disable it unless you're
debugging.

## References

1. https://blog.lucas-simon.com/how-i-took-down-my-site-and-fixed-it-with-memolock  
   Implementation in TypeScript:  
   https://github.com/demipixel/redis-memolock-node/blob/master/src/index.ts
2. https://redis.io/blog/caches-promises-locks/  
   Implementation in Go:  
   https://github.com/kristoff-it/redis-memolock/tree/master
