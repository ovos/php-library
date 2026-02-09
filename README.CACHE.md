# Cache Stores (APCu, Redis, Redisearch)

This library provides a two-tier cache system designed to minimize database load
and speed up response times. **Every store query that returns the same data for
the same input should be cached.** Database queries are expensive - cache is not.

See also: [README.MEMOLOCK.md](README.MEMOLOCK.md) for stampede protection.

## Why cache matters

A typical page load may trigger 10-30 database queries. Many of these return
the same data on every request (e.g., job categories, competences, navigation
menus). Caching these results means:

- **Faster responses** - APCu reads take microseconds, Redis reads take ~1ms
- **Lower database load** - fewer queries = more capacity for writes and complex operations
- **Better scalability** - cache handles traffic spikes that would overwhelm the database

**Rule of thumb:** If data doesn't change on every request, cache it.

## The two tiers

| Tier | Backend | Shared? | Speed | Survives restart? | Tags? |
|------|---------|---------|-------|-------------------|-------|
| **Perishable** | APCu | No (per-worker) | Fastest (~1μs) | No | No |
| **Persistent** | Redis / Redisearch | Yes (all workers/servers) | Fast (~1ms) | Yes | Yes |

**Use perishable (APCu)** for data that is read frequently and changes rarely
within a single request cycle - like lookup tables, categories, and reference data.

**Use persistent (Redis)** for data that must be shared across workers or servers,
or when you need tag-based invalidation.

## Accessing the cache

Access stores via the cache service so they are created with the correct config
and connections:

```php
use Ovos\Service\Cache;

$cacheService = $this->container->get(Cache::SYMBOL);

// Get specific tier
$perishable = $cacheService->getPerishable()->getStore();  // APCu
$persistent = $cacheService->getPersistent()->getStore();   // Redis/Redisearch

// Shorthand (persistent by default)
$store = $cacheService->getStore();                         // Redis/Redisearch
$store = $cacheService->getStore(persistent: false);        // APCu
```

## Store differences

### APCu (`Ovos\Cache\Store\Apcu`)
- In-process memory cache (per PHP worker).
- Fastest option, but not shared across servers or processes.
- No tags support.
- Best for: lookup tables, reference data, anything read-heavy that changes rarely.

### Redis (`Ovos\Cache\Store\Redis`)
- Distributed cache shared across workers/servers.
- Supports tags with `getTags()`, `invalidateTags()`, and `getAllTags()`.
- Uses Redis Pub/Sub for MemoLock queueing (needs a separate queue connection).
- Best for: shared state, session-adjacent data, anything needing tag invalidation.

### Redisearch (`Ovos\Cache\Store\Redisearch`)
- Distributed cache with tag invalidation powered by RediSearch.
- Uses a RediSearch TAG index for fast tag-based invalidation.
- Requires the RediSearch module and `MAXSEARCHRESULTS` set to `-1`.
- Supports tags in `set()` and `invalidateTags()`, but does not expose
  `getTags()` or `getAllTags()`.
- Best for: projects with many tagged cache entries where invalidation speed matters.

Selecting between Redis and Redisearch is done in config:

```yaml
cache:
  persistent:
    store: Redisearch   # or Redis
```

## Real-world usage patterns

### Pattern 1: Cache in a Store with CacheService trait (recommended)

The most common pattern. Use the `CacheService` trait in your store to get
cache access and built-in invalidation:

```php
<?php
declare(strict_types=1);

namespace Stores;

use Models\Competence;
use Ovos\Store\Mysql;
use Ovos\Store\Mysql\Traits\Find;
use Ovos\Store\Mysql\Traits\CacheService;

class Competences extends Mysql
{
    use Find;
    use CacheService;

    public const ?string TABLE = 'competences';
    public const ?string MODEL = Competence::class;

    public function __construct()
    {
        parent::__construct();
        $this->initCacheService();  // Initialize cache in constructor
    }

    /**
     * @return Competence[]
     */
    public function getActive(): array
    {
        // 1. Try cache first (perishable = APCu, fastest)
        $store = $this->cacheService->getPerishable()->getStore();
        $cacheId = self::TABLE;

        if($items = $store->get($cacheId))
        {
            return $items;
        }

        // 2. Cache miss - query database
        $query = $this->query()
            ->select('id, id, name')
            ->where('active = 1')
            ->orderBy('name');
        $statement = $this->prepareQuery($query);
        $statement->execute();

        $items = $this->fetchGrouped($statement, Competence::class);

        // 3. Export to read-only format (smaller serialization, faster reads)
        foreach($items as &$item)
        {
            $item = $item->export();
        }
        unset($item);

        // 4. Store in cache
        $store->set($cacheId, $items);

        return $items;
    }
}
```

### Pattern 2: Multiple cache keys per store

When a store serves different data shapes, use separate cache keys:

```php
class Jobs extends Mysql
{
    use Find;
    use CacheService;

    public const ?string TABLE = 'jobs';

    // Define cache key constants for different data sets
    public const string CACHE_RELATIONS = 'relations';
    public const string CACHE_NAMES = 'names';

    public function __construct()
    {
        parent::__construct();
        $this->initCacheService();
    }

    public function getRelations(): array
    {
        $store = $this->cacheService->getPerishable()->getStore();
        $cacheId = self::TABLE . '_' . self::CACHE_RELATIONS;

        if($items = $store->get($cacheId))
        {
            return $items;
        }

        // ... expensive query with JOINs ...

        $store->set($cacheId, $items);
        return $items;
    }

    public function getNames(): array
    {
        $store = $this->cacheService->getPerishable()->getStore();
        $cacheId = self::TABLE . '_' . self::CACHE_NAMES;

        if($items = $store->get($cacheId))
        {
            return $items;
        }

        // ... lightweight query ...

        $store->set($cacheId, $items);
        return $items;
    }
}
```

### Pattern 3: Cache with a resolver (simplest API)

The resolver pattern combines get + compute + set into a single call.
If the key exists, the cached value is returned. If not, the resolver runs
and the result is cached automatically.

```php
$store = $cacheService->getStore();

$value = $store->get(
    'user:42:profile',
    resolver: fn() => $this->fetchProfileFromDB(42),
    ttl: 300,
);
```

With tags for targeted invalidation:

```php
$value = $store->get(
    'user:42:profile',
    resolver: fn() => $this->fetchProfileFromDB(42),
    ttl: 300,
    tags: ['user', 'user:42'],
);

// Later, when user 42 updates their profile:
$store->invalidateTags(['user:42']);
```

The resolver can also modify tags and TTL dynamically:

```php
$value = $store->get(
    'key',
    resolver: function($store, $key, &$ttl, &$tags)
    {
        $data = $this->computeExpensiveResult();
        $tags[] = 'computed:' . $data->category;
        $ttl = $data->isVolatile ? 60 : 3600;
        return $data;
    },
    ttl: 300,
    tags: ['base-tag'],
);
```

### Pattern 4: Traditional get/set

If you prefer explicit control:

```php
$store = $cacheService->getStore();

if($store->get('key') === null)
{
    $value = $this->expensiveComputation();
    $store->set('key', $value, ttl: 30);
}
```

## Cache invalidation

### Via the CacheService trait

The trait provides `invalidateCache()` which deletes by the store's TABLE constant:

```php
// In a controller after saving data:
$store = new CompetencesStore;
$store->invalidateCache();                         // Deletes cache key "competences"
$store->invalidateCache(Jobs::CACHE_RELATIONS);    // Deletes cache key "jobs_relations"
$store->invalidateCache(Jobs::CACHE_NAMES);        // Deletes cache key "jobs_names"
```

### Via tags (Redis/Redisearch only)

Tags let you invalidate groups of related cache entries at once:

```php
$store->invalidateTags(['user:42']);     // Invalidate everything tagged with user:42
$store->invalidateTags(['products']);    // Invalidate all product-related caches
```

### When to invalidate

Always invalidate after data changes:

```php
// In a controller
if($this->request->isPost())
{
    $banner->fromArray($form->getValues());
    $banner->save();

    // Invalidate the cached version
    $store->invalidateCache(Banner::ID_HOMEPAGE);
}
```

In import/batch operations, invalidate after all writes complete:

```php
// After importing all jobs
$jobsStore->invalidateCache(Jobs::CACHE_NAMES);
$jobsStore->invalidateCache(Jobs::CACHE_RELATIONS);
```

## Stampede protection (MemoLock)

When a popular cache key expires, many requests may try to rebuild it
simultaneously - this is called a **cache stampede**. MemoLock prevents this
by ensuring only one request rebuilds the value while others wait.

**This is enabled by default** when using the resolver pattern. See
[README.MEMOLOCK.md](README.MEMOLOCK.md) for details and advanced usage.

```php
// MemoLock is active automatically with resolvers:
$value = $store->get(
    'popular-key',
    resolver: fn() => $this->expensiveQuery(),
    ttl: 60,
);
```

## Best practices

1. **Always export models before caching** - use `$model->export()` to get a
   lightweight `stdClass`. This reduces serialization size and avoids caching
   unnecessary internal state.

2. **Use perishable (APCu) for read-heavy lookup data** - categories, competences,
   configuration-like data that doesn't change during a deploy.

3. **Use persistent (Redis) when workers need to share** - session-adjacent data,
   computed aggregates, or anything where tag invalidation is needed.

4. **Use meaningful cache keys** - `TABLE` + `_` + purpose constant
   (e.g., `jobs_relations`, `banners_homepage`).

5. **Invalidate precisely** - don't flush entire caches when only one key changed.
   Use specific keys or tags.

6. **Set appropriate TTLs** - short for volatile data (30-60s), longer for stable
   reference data (300-3600s). Perishable cache items without TTL persist until
   the worker restarts.

## Configuration reference

```yaml
cache:
  enabled: yes
  prefix: !ENV CACHE[PREFIX]          # Prevents key collisions between projects
  perishable:
    compression:
      enabled: yes
      threshold: 2048                  # Compress values larger than 2KB
    queue:                             # APCu MemoLock settings
      enabled: yes
      lock_ttl_s: 1                    # Lock duration in seconds
      wait_timeout_s: 2               # Max time to wait for lock
      backoff_min_ms: 5               # Min backoff between retries
      backoff_max_ms: 25              # Max backoff between retries
  persistent:
    connection: redis                  # Which Redis connection to use
    store: Redisearch                  # Redis or Redisearch
    compression:
      enabled: yes
      threshold: 2048
    queue:                             # Redis MemoLock settings
      enabled: yes
      connection: redis                # Separate connection for Pub/Sub
      lock_ttl_ms: 2000               # Lock duration in milliseconds
      wait_attempts: 3                # Number of Pub/Sub wait attempts
```

## Operational notes

- Redis and Redisearch require two Redis connections when queueing is enabled:
  one for data operations and one for MemoLock Pub/Sub.
- For Redisearch, ensure the RediSearch module is loaded and
  `MAXSEARCHRESULTS` is set to `-1` (the store sets this during index creation).
- Use `php cli.php system cache clear` to flush all cache tiers.
