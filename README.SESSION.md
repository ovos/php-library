# Session - Lazy RedisJSON Session Storage

The `Session` service supports two storage handlers, selected by the
`session.handler` config key:

- **`php`** (default) - the native PHP session machinery, configured through
  the `session.ini` block (typically the phpredis `redis` save handler).
  The whole serialized session is read at `session_start()`, guarded by a
  **session-wide lock**, and written back at the end of the request.
- **`json`** - one **RedisJSON document per session**, read and written
  lazily at nested paths. Nothing is loaded up front, there is no
  session-wide lock, and writes are write-through.

See also: [README.MEMOLOCK.md](README.MEMOLOCK.md) - the per-value locks
are built on MemoLock.

## The problem with classic sessions

The blob model serializes *requests*, not just data. With the native redis
save handler and locking enabled:

```
Request A → session_start → acquires the session lock → ... 2s of work ... → write + unlock
Request B → session_start → BLOCKS until A unlocks       (wanted one flag)
Request C → session_start → BLOCKS until A and B unlock  (wanted nothing)
```

Every parallel request sharing the session cookie waits, even when it only
reads one value - or none at all. `session_set_save_handler` cannot fix
this: the save-handler API is blob-based (`read()` returns the whole
serialized session, `write()` persists the whole session), so lazy per-key
access cannot be built on it. The json handler bypasses the native session
machinery entirely and manages the session cookie itself.

**With the json handler:**

```
Request A → getLocked('basket.products') → per-VALUE lock → ... work ... → set() → unlock + notify
Request B → get('user.locale')           → 1 round trip, untouched by A's lock
Request C → get('basket.products')       → waits (Pub/Sub) only for THAT value, then reads A's write
```

## Storage layout

| Key | Content |
|---|---|
| `<prefix>:<sid>` | RedisJSON document, sliding `PEXPIRE` = `session.lifetime` |
| `<prefix>:<sid>:<path>:lock` | per-value MemoLock lock (only while held) |
| `<prefix>:<sid>:<path>:channel` | Pub/Sub release channel (not a key) |
| `<prefix>:activity` | sorted set `sid → last activity` (active-user counting) |

Session ids are 32 hex chars (`random_bytes(16)`); a well-formed cookie id
is accepted as-is (matching `session.use_strict_mode = 0` behavior) -
call `regenerateId()` on login/privilege changes, exactly as with native
sessions.

Reserved document keys: `__meta` (`{created: <unix>}`, stamped atomically
on first write) and `__journey` (the timeline, see below). Everything else
is application data.

## Configuration

```yaml
session:
  handler: json               # php (default) | json
  cookie_name: session
  lifetime: 86400             # sliding TTL, seconds (default: gc_maxlifetime)
  prefix: MYAPP_SESSION       # redis key prefix (default: "session")
  lock:                       # per-value locking (MemoLock)
    enabled: yes
    lock_ttl_ms: 2000         # a crashed holder blocks waiters at most this long
    wait_attempts: 3
  journey:
    requests: no              # auto-append every http request to the timeline
    limit: 200                # kept timeline entries
  ini:                        # used by the php handler only
    save_handler: redis
    save_path: !ENV SESSION[SAVE_PATH]
  connection:                 # json handler storage (+ flush() for both)
    host: !ENV SESSION[HOST]
    port: !ENV SESSION[PORT]
    database: !ENV SESSION[DATABASE]
```

Requirements: the RedisJSON module (bundled with Redis 8) on the session
Redis; the Lua write functions pass multiple keys (the document and the
value-lock keys), so on a Redis Cluster the prefix must carry a
`{hash-tag}`.

Cookie notes: with `session.cookie_lifetime > 0` the cookie is re-sent on
every request, so its expiry **slides together with the document TTL**
(the default `0` = a browser-session cookie needs no slide). Two config
keys are meaningful for the `php` handler only and are ignored under
`json`: the `ini` block and `cache_limiter` (no `session_start()` runs,
so no cache headers are emitted - send your own if a route needs them).
An `autostart` key is read by nothing at all - sessions start lazily on
first access.

Internally `Ovos\Service\Session` picks ONE store when the session starts
- `Session\Store\Native` (`$_SESSION` byref, or a plain local array on
the CLI) or `Session\Store\Json` (the RedisJson handler) - and delegates
every access; php-only projects never instantiate a single json class.

## The path API (works with BOTH handlers)

The recommended access style - handler-agnostic, so code written against
it runs unchanged whether the project has switched to `json` or not:

```php
$session = $services->session;

// reads fetch ONLY the addressed value (json handler)
$products = $session->get('basket.products');        // dotted string...
$products = $session->get(['basket', 'products']);   // ...or array form

$session->set('basket.products', $products);         // parents auto-created
$session->has('user.verified');
$session->remove('console_auth');                    // whole namespace
$session->increment('stats.views');                  // atomic, no lock needed

// INTEGER segments address json array elements (array path form only -
// numeric strings and dotted-string paths stay object keys):
$session->get(['recent.views', 0]);                  // first list entry
$session->set(['items', 2], $patched);               // existing element
```

Arrays are never *created* by index - build lists with `append()`, then
address their elements with integer segments.

Lifecycle, handler-agnostic like the rest of the path API:

```php
$session->getId();          // the active session id (null on the CLI)
$session->regenerateId();   // fresh id on login/privilege change
$session->destroy();        // logout: delete the data, continue on a fresh id
```

### Read-modify-write: update()

The safe way to modify a value based on its current state - lock, read,
apply, write (the write releases the lock and wakes waiting readers);
neither the lock nor the release can be forgotten, and an updater
exception releases the lock too:

```php
$session->update('basket', function(mixed $basket): mixed
{
	$basket['products'][] = $productId;

	return $basket;
});
```

Use `update()` for ANY read-before-write; a plain `get()` + `set()` pair
can lose parallel updates (nothing serializes the two calls).

### Manual locking

The primitives behind `update()`, for flows that span more code:

```php
$basket = $session->getLocked('basket');   // lock taken
// ... work ...
$session->set('basket', $basket);          // write + unlock + notify waiters

// bail-out path without a write:
$session->releaseLock('basket');
```

Parallel readers of a locked value wait (MemoLock Pub/Sub, no polling)
until the writer publishes. Unreleased locks are released at request
shutdown and expire after `lock_ttl_ms` even if the process dies. Under
the `php` handler `getLocked()` is a plain read - the native session-wide
lock already serializes requests.

**Writes honor locks too, atomically.** A blind `set()`/`increment()`/
`append()` from another request does not sail past a held lock: the Lua
side checks the lock keys in the same atomic step as the write and
refuses while one is held; the writer then waits for ONE release and
retries - unconditionally on the retry (availability over strictness,
the lock TTL bounds the wait). Your own locks never block you.

**Locks are hierarchical**: a lock on `basket` (or the whole session, the
`[]` root path) also covers `basket.products` - descendant reads and lock
acquisitions wait for it. The inverse direction is intentionally not
covered: reading `basket` while only `basket.products` is locked returns
a snapshot without waiting (descendant locks cannot be enumerated).

### List appends: append()

A pure append needs no lock at all - it is a single atomic server-side
function (like `increment()`), creating the list and missing parents when
necessary; an optional limit caps the list to its newest entries:

```php
$session->append('basket.products', $productId);        // lock-free, 1 round trip
$session->append('recent.views', $pageId, limit: 20);   // capped list
```

Reach for `update()` only when the new state depends on reading the old
one; appends and increments have atomic primitives of their own.

### Batched reads: getMany()

Several values in ONE round trip (a multi-path `JSON.GET`), keyed by the
dot-joined path - use it when a request needs more than a couple of
values:

```php
$values = $session->getMany(['user.locale', 'basket.count', 'console_auth']);
// ['user.locale' => 'de', 'basket.count' => 3, 'console_auth' => [...]]
```

### Magic accessors (json handler)

The magic accessors are the COMPATIBILITY layer. A magic read peeks at
the stored type: a **scalar materializes as itself** - legacy code
reading flags and ids keeps working unchanged - while a **container or a
missing value stays a lazy `Ovos\Session\Node`** proxy:

```php
$session->active_child;                     // 12 - a scalar IS its value
$session->active_child ?? 0;                // missing → 0 (__isset is a real check)
$session->basket;                           // Node - containers stay lazy
$session->basket->products->get();          // explicit terminal for containers
$session->basket->products = [1, 2];        // magic writes are write-through
isset($session->user->verified);            // JSON.TYPE
foreach($session->basket->products as $p)   // iteration materializes
```

Missing values are Nodes (not null) on purpose: the classic
lazy-create-namespace pattern (`if($session->ns === null) ...` followed
by property writes) keeps persisting through the Node instead of
silently writing into a detached object. The peek costs one extra round
trip per magic step - new code should prefer the path API
(`get()`/`set()`/`update()`/`append()`/`getMany()`), which always costs
exactly one; `$node->child('key')` forces traversal without the peek
(e.g. to call `increment()` on an existing counter).

> **The truthiness landmine.** Because a missing value is a Node and PHP
> objects are always truthy, `if($session->neverSet)` is **true** under
> `json` but false under `php`. `isset()` and `??` behave identically
> under both handlers (they route through a real existence check) - so
> write `if(isset($session->flag))`, `$session->flag ?? false`, or use
> the path API (`$session->get('flag')` returns real `null`). This is
> the sharpest migration caveat after write-through.

## Journey: timeline and navigation path

Each session document can record what the user did - custom actions
(called manually from application code) and requests - and replay them as
a timeline / navigation path:

```php
$session->addAction('ticket bought', ['ticket' => $id]);
$session->addAction('signed up for newsletter');
$session->addRequest('GET', '/tickets/42');   // or journey.requests: yes

$session->getJourney();
// [
//   ['t' => 1783333632, 'type' => 'request', 'method' => 'GET', 'url' => '/tickets/42'],
//   ['t' => 1783333640, 'type' => 'action', 'action' => 'ticket bought', 'data' => ['ticket' => 42]],
// ]
```

Appends are atomic (`JSON.ARRAPPEND` + trim in one server-side function)
and the array is capped at `journey.limit` entries.

## Active users

Every request that touches the session stamps `<prefix>:activity`
(piggybacked on the TTL slide - no extra round trip). Counting is one
`ZCOUNT`:

```php
$session->countActive();      // sessions active in the last 5 minutes
$session->countActive(3600);  // ... the last hour
```

Returns `null` under the `php` handler (it cannot know).

## Garbage collection

PHP's session GC (`session.gc_probability` / `gc_divisor` / `gc_maxlifetime`)
does **not** apply to the json handler - the native machinery is bypassed.
**Redis key expiration is the garbage collector**, and every key the handler
creates carries a TTL by construction:

- the **document**: every write function (`session_set` / `session_increment`
  / `session_append` / `session_rename`) ends with `PEXPIRE lifetime`, and the
  once-per-request `touch()` slides it on reads too - a session lives exactly
  `lifetime` past its last activity, then Redis expires it;
- **locks**: always `SET ... PX lock_ttl_ms` - a crashed holder's lock
  self-collects within milliseconds;
- the **activity sorted set** deliberately has *no* TTL (a `volatile-*`
  eviction policy can then never evict it); its stale members are trimmed
  opportunistically - on every `countActive()` and on ~1% of touches - so it
  stays bounded by the sessions active within one `lifetime` window. For a
  deterministic sweep (e.g. on a low-traffic site, where opportunistic
  trimming has nothing to ride on), cron the CLI action:

  ```bash
  php cli.php system sessions gc   # Session::gc() - trims the activity index
  ```

**Eviction policy** on the session Redis: session keys carry TTLs, so under
memory pressure `volatile-lru`/`volatile-ttl` may evict *live* sessions early
(users logged out) - the same exposure the phpredis save handler always had.
On a dedicated session instance prefer `noeviction` with adequate
`maxmemory`; on an instance shared with a cache, `volatile-ttl` is a
reasonable compromise (short-lived cache entries are evicted long before
day-long sessions).

## Value encoding

- scalars, lists, maps → plain JSON, readable per-path
- non-UTF-8 (binary) strings → an opaque serialized leaf (JSON cannot
  carry them); transparently restored on read
- `ArrayObject` (incl. `Ovos\ArrayObject`) → JSON **object** - stored
  namespaces stay path-addressable; read back as a PHP **array**
- `JsonSerializable` → its JSON form
- any other object → an opaque serialized **leaf**
  (`{"__php_serialized__": "..."}`) restored on read; no path access into it

## Searching sessions: the RediSearch index

With the RediSearch module, the session documents can be indexed and
queried - "who is logged in", "sessions of user X", "sessions created
today". The schema is **per project**: the config maps field aliases to
document paths and index types:

```yaml
session:
  index:
    enabled: yes              # REQUIRES the session connection on DATABASE 0
    # name: MYAPP_SESSION:index
    fields:                   # alias: {path, type, sortable}
      authenticated:
        path: console_auth.authenticated
        type: tag             # exact values: @authenticated:{true}
      email:
        path: user.email
        type: text            # full-text: @email:(mg*)
      created:
        path: __meta.created
        type: numeric         # ranges: @created:[1783000000 +inf]
        sortable: yes         # enables SORTBY created
```

```php
$index = $session->index();                          // null when disabled
$index->count('@authenticated:{true}');              // logged-in sessions
$result = $index->search('@created:[' . (time() - 3600) . ' +inf]',
	limit: 50, sortBy: 'created', ascending: false); // newest sessions first
// $result = ['total' => int, 'sessions' => [sid => document array]]

$result = $index->searchIds('@authenticated:{true}', limit: 1000);
// ['total' => int, 'ids' => [sid, ...]] - NOCONTENT, no document payloads
```

The index is created on first use and self-heals when it vanishes
(deploy, `FLUSHDB`) **and when the schema drifts**: `ensure()` compares
the live index (field paths, types, sortability) against the config, so
editing `index.fields` recreates the index instead of quietly serving
searches against the old shape (redis re-indexes the documents in the
background). Documents are indexed as they are written. **Hard
constraint**: RediSearch only indexes **database 0** - `ensure()` refuses
any other database loudly. Locks/activity keys under the same prefix are
not JSON and are skipped by the index automatically.

## Migration notes (php → json)

Compatibility is at the **config level**: projects stay on `handler: php`
untouched until their call sites are migrated. The semantic differences:

1. **Write-through, no write-back.** Mutating a fetched value does NOT
   persist it - `set()` it back. In-place patterns like
   `$session->auth->return_url->setLocale(null)` must become
   read → modify → `set()`.
2. **No by-reference binding.** `$x = &$session->ns` (and lazy
   `new ArrayObject` namespace creation) becomes the path API:
   `$session->get('ns.key')` / `$session->set('ns.key', $v)` - and any
   read-before-write becomes `$session->update('ns.key', fn($v) => ...)`.
   Magic reads of SCALARS keep working as-is (the accessor materializes
   them); calling `ArrayObject` methods (`exchangeArray`, `getArray`) on
   a namespace fails loudly on the Node and marks a call site to migrate.
3. **`exchangeArray([])` to clear** becomes `$session->remove('ns')`.
4. **ArrayObjects come back as arrays** (see encoding above).
5. **Dots in keys**: the dotted-string path form splits on `.` - use the
   array form (`['ns', 'key.with.dots']`) for such keys.
6. **Truthiness**: `if($session->neverSet)` is TRUE under json (missing
   values are Node objects, and objects are truthy) - use `isset()`,
   `??` or the path API, which behave identically under both handlers.
7. `Ovos\View\Helper\Messages` (flash messages) is already
   handler-aware - no changes needed in views.
