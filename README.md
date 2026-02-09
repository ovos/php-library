# ovos php library

### GitHub
https://github.com/ovos/php-library

## Overview

This library is a general-purpose MVC framework and toolkit for PHP applications.
It provides core infrastructure (application lifecycle, DI container, services,
configuration, logging), along with reusable modules for cache, storage,
controllers, CLI tooling, testing, and common utilities.

It is designed to work with:
- https://github.com/ovos/php-module-system (system management: cache, migrations, tests, benchmarks)
- https://github.com/ovos/php-module-admin (administration panel)

## Requirements

* PHP 8.3 - 8.5
* MySQL 8.0 - 9.0
* Extensions: `apcu`, `yaml`, `redis`, `intl`, `mbstring`, `pdo`, `json`, `simplexml`, `openssl`, `curl`, `zend-opcache`

## Table of Contents

- [Installation](#installation)
- [Project Structure](#project-structure)
- [Bootstrap & Entry Points](#bootstrap--entry-points)
- [Environment File (.env)](#environment-file-env)
- [Configuration (environments.yml)](#configuration-environmentsyml)
  - [Config Field Reference](#config-field-reference)
- [Modules](#modules)
- [Routing](#routing)
- [Controllers](#controllers)
- [Models](#models)
- [Stores](#stores)
- [Views](#views)
- [Plugins](#plugins)
- [Services](#services)
- [Dependency Injection (Container)](#dependency-injection-container)
- [Translations](#translations)
- [Migrations](#migrations)
- [Cache](#cache)
- [CLI Commands](#cli-commands)

---

## Installation

1. Add SSH config for private repositories:
```
Host ovos.php-library
    HostName github.com
    PreferredAuthentications publickey
    IdentityFile ~/.ssh/ovos.php-library
```

2. Create a new project directory and `composer.json`:
```json
{
  "name": "ovos/my-project",
  "type": "project",
  "repositories": [
    { "type": "git", "url": "git@github.com:ovos/php-library.git" },
    { "type": "git", "url": "git@github.com:ovos/php-module-system.git" },
    { "type": "git", "url": "git@github.com:ovos/php-module-admin.git" }
  ],
  "require": {
    "php": ">=8.3.0",
    "ext-apcu": "*",
    "ext-redis": "*",
    "ext-yaml": "*",
    "ext-intl": "*",
    "ext-pdo": "*",
    "ovos/php-library": "dev-release/8.5",
    "ovos/php-module-system": "dev-release/8.5"
  },
  "autoload": {
    "psr-4": {
      "Controllers\\": "application/controllers",
      "Models\\": "application/models",
      "Stores\\": "application/stores",
      "Plugins\\": "application/plugins",
      "Components\\": "application/components",
      "Widgets\\": "application/widgets",
      "MyApp\\": "libraries/MyApp"
    }
  }
}
```

3. Run:
```bash
composer install
cp .env.example .env
```

---

## Project Structure

A typical project using php-library follows this layout:

```
project/
├── .env                              # Environment variables (not committed)
├── .env.example                      # Template for .env
├── init.php                          # Bootstrap initialization
├── cli.php                           # CLI entry point
├── composer.json
├── application/
│   ├── configs/
│   │   └── environments.yml          # Main configuration file
│   ├── controllers/                  # HTTP & CLI controllers
│   │   ├── Index.php                 # Default controller
│   │   └── Admin/
│   │       └── Banner.php            # Nested controller → /admin/banner
│   ├── models/                       # Data models
│   │   └── User.php
│   ├── stores/                       # Data access layer (repositories)
│   │   └── Users.php
│   ├── plugins/                      # Controller plugins (middleware)
│   │   ├── Auth.php
│   │   └── Layout/
│   │       └── Page.php
│   ├── components/                   # Form components
│   │   └── Admin/
│   │       └── BannerForm.php
│   ├── widgets/                      # Reusable view widgets
│   ├── views/                        # View templates (.phtml)
│   │   ├── index/
│   │   │   └── index.phtml
│   │   └── layouts/
│   │       └── page.phtml
│   ├── translations/                 # .po/.mo translation files
│   │   ├── phrases.php               # Translation key registry
│   │   ├── de.po / de.mo
│   │   └── en.po / en.mo
│   ├── migrations/                   # Database migrations
│   │   └── 20210226000000_Struct.php
│   └── tests/                        # Test classes
├── libraries/
│   └── MyApp/                        # Project-specific library code
│       ├── Services.php              # Custom Services class
│       └── Service/
│           └── Auth.php              # Custom authentication service
├── public/                           # Web root (DocumentRoot)
│   └── index.php                     # HTTP entry point
└── vendor/
    └── ovos/
        ├── php-library/              # This framework
        └── php-module-system/        # System module (cache, migrations, tests)
```

---

## Bootstrap & Entry Points

### HTTP Entry Point (`public/index.php`)

Point your web server's DocumentRoot to the `public/` directory.

```php
<?php
namespace Ovos;

define('BASE_DIR', dirname(__DIR__) . DIRECTORY_SEPARATOR);
require_once BASE_DIR . 'init.php';

$app = new Application();
$app->run();
```

### CLI Entry Point (`cli.php`)

```php
<?php
namespace Ovos;

define('BASE_DIR', __DIR__ . DIRECTORY_SEPARATOR);
require_once BASE_DIR . 'init.php';

$app = new Application(Application::INT_CLI);
$app->run();
```

### Bootstrap Initialization (`init.php`)

```php
<?php
namespace Ovos;

if(date_default_timezone_get() === '')
{
    date_default_timezone_set('Europe/Vienna');
}

require_once BASE_DIR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
```

### Application Lifecycle

The `Application` constructor runs these steps in order:

1. **init()** - Register Application, Request, Router, Memory in container
2. **initEnvironment()** - Load `.env` and `environments.yml`
3. **initShutdownHandler()** - Register shutdown function for response sending
4. **initBootstrap()** - Select bootstrap config (multi-app support)
5. **initDomain()** - Match current domain from config
6. **initConstants()** - Define `SYSTEM_HOST`, `SYSTEM_PATH`, `LOGS_DIR`, etc.
7. **initProtocol()** - Enforce HTTPS/HTTP as configured
8. **initModules()** - Load modules (controllers, views, translations)
9. **initServices()** - Register and instantiate services

Then `$app->run()` routes the request and dispatches to a controller.

---

## Environment File (.env)

The `.env` file stores environment-specific secrets (database credentials, API keys) using INI format. It is loaded before the YAML config and its values are accessible via `!ENV` tags in the configuration.

### Example `.env.example`

```ini
ENV = development

[SESSION]
HOST = unix:///var/run/redis/redis.sock
DATABASE = 2
SAVE_PATH = "unix:///var/run/redis/redis.sock?database=2&timeout=2&prefix=MYAPP_SESSION:"

[MYSQL]
HOST = localhost
DATABASE = my_project
USERNAME = root
PASSWORD = root

[REDIS]
HOST = localhost
PORT = 6379
DATABASE = 0

[CACHE]
PREFIX = myproject

[USER]
2FA = no
```

**Key points:**
- `ENV` determines which top-level key in `environments.yml` is used (e.g., `development`, `production`)
- Sections like `[MYSQL]` create grouped variables accessed as `MYSQL[HOST]`, `MYSQL[DATABASE]`, etc.
- Copy `.env.example` to `.env` and adjust values for your local setup
- Never commit `.env` to version control

---

## Configuration (environments.yml)

The main configuration file lives at `application/configs/environments.yml`. It uses YAML format with YAML anchors (`&name`) and aliases (`*name`) for DRY inheritance between environments.

### Minimal Configuration

```yaml
production: &production
  vendor: my-project
  system: &system
    path: /
    bootstraps:
      - path:
        controller:
          http: index
          cli: cli
    domain: www.my-project.com
    protocol: https
    debug: no

    # Locales
    locales:
      de:
        symbol: de_AT
        name: Deutsch
        language: de
        country: AT
        default: yes
      en:
        symbol: en_US
        name: English
        language: en
        country: US

    # Modules
    modules:
      application:
        path: application
        controllers: true
        translations: true
        views: true
        vendor: false
      system:
        path: vendor/ovos/php-module-system/application
        controllers: true
        translations: true
        views: true

    # Migrations
    migrations:
      - vendor/ovos/php-module-system/application/migrations
      - application/migrations

    # Tests & Benchmarks
    tests:
      - vendor/ovos/php-library/tests
      - application/tests
    benchmarks:
      - vendor/ovos/php-library/benchmarks

    # Services
    services:
      http:
        - Events
        - Logger
        - Benchmark
        - Session
        - Cookies
        - Cache
      cli:
        - Events
        - Logger
        - Benchmark
        - Cache

    # Plugins
    plugins:
      default:
        http:
          - Locales
          - Vendor
          - Layout\Page
          - Form
        cli:
          - Vendor
      groups: []

    # Profilers
    profilers: &profilers
      enabled: no
      queries:
        limit: 20
      append:
        http: no
        cli: yes
      helpers:
        - queries
        - console
        - benchmark

    # View helpers
    view_helpers:
      namespaces:
        - MyApp\View\Helper\

    # Collectors
    collectors:
      system:
        controller: System\Collector
        action: collect
        days: 180

  # Cookies
  cookies: &cookies
    prefix: myapp-
    samesite: Lax

  # Database connections
  connections: &connections
    mysql:
      type: mysql
      host: !ENV MYSQL[HOST]
      database: !ENV MYSQL[DATABASE]
      username: !ENV MYSQL[USERNAME]
      password: !ENV MYSQL[PASSWORD]
    redis:
      type: redis
      host: !ENV REDIS[HOST]
      port: !ENV REDIS[PORT]
      database: !ENV REDIS[DATABASE]
      connect_timeout: 1
      read_timeout: 1

  # Session
  session: &session
    cookie_name: session
    cache_limiter: private_no_expire, must-revalidate
    ini:
      save_handler: redis
      save_path: !ENV SESSION[SAVE_PATH]

  # Encryption
  encryption:
    method: AES-128-GCM
    key: YOUR_HEX_KEY_HERE  # bin2hex(random_bytes(16))

  # Database
  database: &database
    connection: mysql

  # Cache
  cache: &cache
    enabled: yes
    prefix: !ENV CACHE[PREFIX]
    perishable: &cache-perishable
      compression:
        enabled: yes
        threshold: 2048
      queue:
        enabled: yes
        lock_ttl_s: 1
        wait_timeout_s: 2
    persistent: &cache-persistent
      connection: redis
      store: Redisearch
      compression:
        enabled: yes
        threshold: 2048
      queue:
        enabled: yes
        connection: redis
        lock_ttl_ms: 2000
        wait_attempts: 3

  # CLI
  cli:
    executable: php

development: &development
  <<: *production
  system: &development-system
    <<: *system
    path: /my-project/
    domain: null
    domains:
      - localhost
    protocol: https
    debug: yes
    profilers:
      <<: *profilers
      enabled: yes

  database:
    <<: *database

  cache:
    <<: *cache
    persistent:
      <<: *cache-persistent
```

### Config Field Reference

| Field | Description |
|-------|-------------|
| `vendor` | Project identifier, used for vendor-specific translation paths |
| `system.path` | Base URL path (e.g., `/` for root, `/my-project/` for subfolder) |
| `system.bootstraps` | Array of app bootstraps. Each has a `path` and default `controller` for http/cli |
| `system.domain` | Single domain for the environment. Use `null` if using `domains` |
| `system.domains` | Array of accepted domains (for development with multiple hosts) |
| `system.port` | Custom port number (if not standard 80/443) |
| `system.protocol` | `https` or `http` - enforced with redirect |
| `system.debug` | `yes`/`no` - show exceptions and debug info |
| `system.locales` | Locale definitions keyed by URL name (e.g., `de`, `en`) |
| `system.locales.{name}.symbol` | Full locale symbol (e.g., `de_AT`, `en_US`) |
| `system.locales.{name}.default` | `yes` to mark as default locale |
| `system.locales.{name}.controllers` | Array of controllers where this locale is unlocked |
| `system.modules` | Module definitions (see [Modules](#modules)) |
| `system.services` | Service lists for `http` and `cli` interfaces |
| `system.services.container` | Custom Services class (e.g., `\MyApp\Services`) |
| `system.plugins` | Plugin configuration (see [Plugins](#plugins)) |
| `system.profilers` | Profiler/debug output settings |
| `system.view_helpers.namespaces` | Custom View helper namespaces (checked before `Ovos\View\Helper\`) |
| `system.migrations` | Array of paths to scan for migration files |
| `system.tests` | Array of paths to scan for test files |
| `system.benchmarks` | Array of paths to scan for benchmark files |
| `system.collectors` | Garbage collection / cleanup jobs config |
| `connections.mysql` | MySQL connection: `host`, `database`, `username`, `password` |
| `connections.redis` | Redis connection: `host`, `port`, `database`, timeouts |
| `session` | Session config: `cookie_name`, `save_handler`, `save_path` |
| `cookies.prefix` | Cookie name prefix |
| `cookies.samesite` | SameSite attribute: `Lax`, `Strict`, or `None` |
| `database.connection` | Which connection to use for models/stores (default: `mysql`) |
| `database.encryption` | Database-level encryption config (`method`, `key`) |
| `encryption` | System-level encryption config (`method`, `key`) |
| `cache.enabled` | `yes`/`no` - master cache switch |
| `cache.prefix` | Cache key prefix (prevents collisions between projects) |
| `cache.perishable` | APCu (per-worker memory) cache config |
| `cache.persistent` | Redis (distributed) cache config |
| `cache.persistent.store` | `Redis` or `Redisearch` backend |
| `http_auth` | HTTP Basic Auth: `enabled`, `username`, `password`, `realm`, `whitelist` |
| `smtp` | SMTP mail config: `host`, `port`, `encryption`, `username`, `password` |

### Accessing Config in Code

```php
// Global function
$config = config();

// Via application
$config = $this->app->getConfig();

// Access values
$debug = $config->system->debug;                    // Direct property access
$host = $config->connections->mysql->host;           // Nested access
$value = $config->getPath('system.cache.enabled');   // Dot-notation path
$value = $config->getPath(['system', 'cache']);       // Array path
```

---

## Modules

Modules are self-contained packages with controllers, views, translations, and more. They are registered in `environments.yml`:

```yaml
system:
  modules:
    application:              # Your project code
      path: application
      controllers: true       # Register controllers for routing
      translations: true      # Add translations path
      views: true             # Add views to include path
      vendor: false           # No vendor-specific translations subfolder
    system:                   # System module (from composer)
      path: vendor/ovos/php-module-system/application
      controllers: true
      translations: true
      views: true
```

Each module follows the same directory structure:
```
module/
├── controllers/       # Controller classes
├── models/            # Model classes
├── stores/            # Store classes
├── plugins/           # Plugin classes
├── views/             # .phtml templates
├── translations/      # .po/.mo translation files
├── migrations/        # Database migrations
└── widgets/           # Reusable widget components
```

**Module loading** happens during `initModules()`:
- If `controllers: true`, the module's controllers become routable
- If `translations: true`, the `translations/` directory is registered with the Translator
- If `views: true`, the `views/` directory is added to PHP's include path (view files are resolved by searching through all registered paths)

---

## Routing

URLs are automatically mapped to controllers and actions:

```
HTTP: /controller/action/param1/value1/param2/value2
CLI:  php cli.php controller action param1 value1
```

### URL Mapping Examples

| URL | Controller Class | Action Method | Params |
|-----|-----------------|---------------|--------|
| `/` | `Controllers\Index` | `index()` | - |
| `/user/login` | `Controllers\User` | `login()` | - |
| `/admin/banner` | `Controllers\Admin\Banner` | `index()` | - |
| `/select/job/id/42` | `Controllers\Select` | `job(int $id)` | `id=42` |

**How it works:**
- The Router scans all module `controllers/` directories for available controllers
- Controller names use StudlyCase (file `Admin/Banner.php` = class `Controllers\Admin\Banner`)
- The default action is `index` if no action segment is present
- Remaining URL segments become named parameters (`/key/value` pairs)
- Action method parameters are auto-filled from URL parameters by name

### Automatic Type Casting

All URL and CLI parameters arrive as strings. The framework automatically casts them
to match the type declarations on your action method using PHP reflection:

```php
// GET /select/job/id/42/rating/3.5
public function job(int $id, float $rating): Response
{
    // $id is automatically cast to int (42), $rating to float (3.5)
}
```

**Supported type casts:**

| Declared type | Cast | Example |
|---------------|------|---------|
| `int`, `?int` | `(int)` | `"42"` → `42` |
| `float`, `?float` | `(float)` | `"3.5"` → `3.5` |

Additionally, the Router converts these string literals **before** they reach the action method:

| String value | Converted to |
|--------------|-------------|
| `"true"`, `"yes"` | `true` |
| `"false"`, `"no"` | `false` |
| `"null"` | `null` |

This applies to both HTTP and CLI parameters:

```
HTTP: /events/list/active/yes/page/2
CLI:  php cli.php events list active yes page 2
```

```php
public function list(bool $active, int $page): Response
{
    // $active = true (from "yes"), $page = 2 (from "2")
}
```

Parameters are matched by name using camelCase conversion, so a URL parameter
`company-id` matches a method parameter `$companyId`.

---

## Controllers

Controllers handle HTTP requests and CLI commands. They extend `Ovos\Controller`.

### HTTP Controller Example

```php
<?php
declare(strict_types=1);

namespace Controllers;

use Ovos\Controller;
use Ovos\Response;
use Ovos\View;
use Stores\Users as UsersStore;

class Users extends Controller
{
    /**
     * GET /users → index action (default)
     */
    public function index(): Response
    {
        $view = new View('users/index.phtml');

        $store = new UsersStore;
        $view->users = $store->getAll();

        return new Response\Html($view->render());
    }

    /**
     * GET /users/edit/id/42 → edit action with named parameter
     */
    public function edit(int $id): Response
    {
        $view = new View('users/edit.phtml');

        $store = new UsersStore;
        $view->user = $store->find(['id' => $id]);

        return new Response\Html($view->render());
    }

    /**
     * JSON response example
     */
    public function data(): Response
    {
        $response = new Response\Json;
        $response->data = ['items' => [1, 2, 3]];
        return $response;
    }

    /**
     * POST handling example
     */
    public function save(): Response
    {
        $response = new Response\Json;

        if($this->request->isPost())
        {
            $post = $this->request->getPost();
            // process form data...
            $response->success = true;
        }

        return $response;
    }

    /**
     * Redirect example
     */
    public function old(): Response
    {
        return (new Response\Redirect('users'))
            ->withQueryString();
    }
}
```

### CLI Controller Example

```php
<?php
declare(strict_types=1);

namespace Controllers;

use Ovos\Controller;
use Ovos\Functions;

// Usage: php cli.php import run
class Import extends Controller\Cli
{
    use Controller\Traits\Cli;

    public function run(): void
    {
        $this->log('Starting import...');

        // Do work...

        $this->log('Import complete: %d items processed', $count);
    }
}
```

### Registering Custom Plugins in a Controller

```php
class Index extends Controller
{
    public function registerPlugins(): void
    {
        // Conditionally add plugins
        if($this->request->getActionMethod() === 'index')
        {
            $this->addPlugin(new Layout\Homepage);
        }
        else
        {
            $this->addPlugin(new Layout\Page);
        }
    }
}
```

### Available Properties in Controllers

| Property | Description |
|----------|-------------|
| `$this->app` | Application instance |
| `$this->request` | Current Request object |
| `$this->container` | DI Container |
| `$this->params` | URL parameters |

### Available Methods

| Method | Description |
|--------|-------------|
| `$this->request->isPost()` | Check if POST request |
| `$this->request->getPost()` | Get POST data |
| `$this->request->getLocale()` | Get current Locale |
| `$this->_('phrase')` | Translate a phrase (via Translatable trait) |
| `$this->_n('one', 'many', $n)` | Translate plural |
| `$this->addPlugin($plugin)` | Register a plugin |
| `$this->setDispatched(true)` | Stop further dispatching (e.g., after redirect) |

---

## Models

Models represent database records. They extend `Ovos\Model\Mysql`.

### Creating a Model

```php
<?php
declare(strict_types=1);

namespace Models;

use Ovos\Model\Mysql;
use Ovos\Model\Mysql\Template\Timestamps;
use Ovos\Pdo\Expression;
use Override;
use Stores\Users;

/**
 * Use @property annotations for IDE autocompletion
 *
 * @property int $id
 * @property string $username
 * @property string $email
 * @property string $role
 * @property int $active
 * @property mixed $created_at
 * @property mixed $modified_at
 */
class User extends Mysql
{
    // Constants for roles, statuses, etc.
    public const string ROLE_ADMIN = 'admin';
    public const string ROLE_USER = 'user';

    // Which properties to expose (e.g. for JSON serialization)
    public const array PROPERTIES_PERSISTABLE = [
        'id', 'username', 'email', 'role',
    ];

    /**
     * Link to the corresponding Store class
     */
    #[Override]
    public static function getStoreClass(): string
    {
        return Users::class;
    }

    /**
     * Set up templates and setters
     */
    #[Override]
    public function setUp(): void
    {
        // Automatically manages created_at and modified_at columns
        $this->addTemplate(new Timestamps);
    }

    // Custom business logic methods
    public function isAdmin(): bool
    {
        return $this->role === self::ROLE_ADMIN;
    }

    public function softDelete(): static
    {
        $this->deleted_at = new Expression('NOW()');
        $this->save();
        return $this;
    }
}
```

### Model Operations

```php
// Create a new record
$user = new User;
$user->username = 'john';
$user->email = 'john@example.com';
$user->role = User::ROLE_USER;
$user->insert();          // INSERT into database, auto-increment ID is set

// Update an existing record
$update = new User;
$update->email = 'new@example.com';
$user->update($update);   // UPDATE only the modified fields

// Save (insert or update depending on existence)
$user->email = 'changed@example.com';
$user->save();

// Delete
$user->delete();

// Check if record was fetched from database
$user->exists();           // true if fetched with primary key

// Array conversion
$array = $user->toArray();
$user->fromArray(['email' => 'test@example.com']);

// JSON serialization (respects PROPERTIES_PERSISTABLE if defined)
json_encode($user);
```

---

## Stores

Stores are the data access layer (repositories). They extend `Ovos\Store\Mysql` and provide query building and fetching.

### Creating a Store

```php
<?php
declare(strict_types=1);

namespace Stores;

use Models\User;
use Ovos\Store\Mysql;
use Ovos\Store\Mysql\Traits\Find;
use PDO;

class Users extends Mysql
{
    use Find;  // Adds find() and findAll() helper methods

    public const ?string TABLE = 'users';

    public const ?string MODEL = User::class;

    /**
     * Custom query: find user by email
     */
    public function findByEmail(string $email): false|User
    {
        $query = $this->query()
            ->select()
            ->where('email = ?');

        $statement = $this->prepareQuery($query);
        $statement->execute([$email]);

        return $statement->fetchObject(User::class);
    }

    /**
     * Custom query: get all active users
     */
    public function getActive(): array
    {
        $query = $this->query()
            ->select()
            ->where('active = 1')
            ->orderBy('username', 'ASC');

        $statement = $this->prepareQuery($query);
        $statement->execute();

        return $this->fetchAll($statement, User::class);
    }

    /**
     * Custom query with COUNT
     */
    public function countActive(): int
    {
        $query = $this->query()
            ->select('COUNT(id)')
            ->where('active = 1');

        $statement = $this->prepareQuery($query);
        $statement->execute();

        return (int)$statement->fetchColumn();
    }

    /**
     * Execute a DELETE/UPDATE and return affected row count
     */
    public function deleteOlderThan(int $days): int
    {
        $query = $this->query()
            ->delete('created_at <= NOW() - INTERVAL ' . $days . ' DAY');

        return $this->executeQuery($query);
    }
}
```

### Using the Find Trait

The `Find` trait adds convenient lookup methods:

```php
$store = new Users;

// Find by primary key(s)
$user = $store->find(['id' => 42]);               // Returns User|false

// Find multiple records
$users = $store->findAll(['role' => 'admin']);      // Returns User[]
```

### Query Builder

The query builder provides a fluent interface:

```php
$query = $this->query()
    ->select('id, username, email')           // SELECT columns
    ->from('users')                            // FROM table (optional, uses TABLE const)
    ->join('orders', 'orders.user_id = users.id')  // JOIN
    ->where('active = ?')                      // WHERE
    ->andWhere('role = ?')                     // AND WHERE
    ->orWhere('email LIKE ?')                  // OR WHERE
    ->groupBy('role')                          // GROUP BY
    ->orderBy('created_at', 'DESC')            // ORDER BY
    ->limit(10)                                // LIMIT
    ->offset(20);                              // OFFSET

$statement = $this->prepareQuery($query);
$statement->execute([$active, $role, $emailPattern]);
```

### Store Usage in Controllers

```php
// Direct instantiation
$store = new UsersStore;
$user = $store->findByEmail('john@example.com');

// Via Container (for dependency injection)
$store = $this->container->getClass(UsersStore::class);
```

---

## Views

Views are `.phtml` template files that mix PHP and HTML. They extend `Ovos\View`.

### Rendering a View in a Controller

```php
public function index(): Response
{
    $view = new View('users/index.phtml');

    // Assign variables to the view
    $view->users = $store->getAll();
    $view->title = 'User List';

    return new Response\Html($view->render());
}
```

### View Template Example (`views/users/index.phtml`)

```php
<?php
/** @var \Ovos\View $this */
?>

<h1><?= $this->_('Users') ?></h1>

<ul>
<?php foreach($this->users as $user): ?>
    <li>
        <a href="<?= $this->url('users', 'edit', ['id' => $user->id]) ?>">
            <?= $this->escape($user->username) ?>
        </a>
    </li>
<?php endforeach ?>
</ul>
```

### Available Variables in Views

| Variable | Description |
|----------|-------------|
| `$this->app` | Application instance |
| `$this->config` | Configuration (ArrayObject) |
| `$this->request` | Current Request |
| `$this->url` | Current URL string |
| `$this->controller` | Current controller name |
| `$this->action` | Current action name |
| `$this->locale` | Current Locale object |
| `$this->interface` | Interface type (`http` or `cli`) |

### Partials

Render a sub-template with its own variable scope:

```php
<?= $this->partial('users/partials/row.phtml', ['user' => $user]) ?>
```

### View Helpers

View helpers are static utility classes accessed via method calls. Built-in helpers include:

```php
// In a .phtml template:

// Page title
<?php $this->title()->set('My Page Title') ?>

// Placeholders (content blocks)
<?php $this->placeholders()->header->append('<link rel="stylesheet" href="custom.css">') ?>

// URL generation
<?= $this->url('users', 'edit', ['id' => $user->id]) ?>

// Asset management
<?= $this->asset()->css('styles/main.css') ?>
<?= $this->asset()->js('scripts/app.js') ?>

// Messages (flash messages)
<?php $this->messages()->success('Saved successfully!') ?>
```

### Custom View Helpers

Register custom namespaces in config:

```yaml
system:
  view_helpers:
    namespaces:
      - MyApp\View\Helper\
```

Then create your helper class:

```php
<?php
namespace MyApp\View\Helper;

class MyHelper
{
    public function format(string $value): string
    {
        return strtoupper($value);
    }
}
```

Usage in views: `<?= $this->myHelper()->format('hello') ?>`

---

## Plugins

Plugins are controller middleware that run before and/or after every action dispatch. They extend `Ovos\Controller\Plugin`.

### Creating a Plugin

```php
<?php
declare(strict_types=1);

namespace Plugins;

use Ovos\Container\Inject;
use Ovos\Controller\Plugin;
use Ovos\Response;
use Override;

class Auth extends Plugin
{
    public const string SYMBOL = 'auth';

    public function __construct(
        #[Inject('auth')] ?MyAuthService $authService,
    )
    {
        parent::__construct();
        $this->authService = $authService;
    }

    /**
     * Runs BEFORE the controller action
     */
    #[Override]
    public function preDispatch(): void
    {
        if($this->authService->isAuthenticated())
        {
            return;
        }

        // Redirect to login
        $response = new Response\Redirect('user/login');
        $this->getController()->setDispatched(true);
    }

    /**
     * Runs AFTER the controller action
     */
    #[Override]
    public function postDispatch(): void
    {
        // Post-processing logic
    }
}
```

### Layout Plugin Example

Layout plugins wrap the controller's response in a layout template:

```php
<?php
declare(strict_types=1);

namespace Plugins\Layout;

use Ovos\Plugins\Layout;
use Override;
use Widgets\Menu;

class Page extends Layout
{
    protected array $placeholders = ['bodyEnd'];

    public function __construct(string $layout = 'page.phtml')
    {
        parent::__construct($layout);
    }

    #[Override]
    public function preDispatch(): void
    {
        // Build navigation menu
        $menu = new Menu('widgets/footer-menu.phtml');
        $menu->add(new Menu\Item($this->_('Contact'), 'contact'));
        $menu->add(new Menu\Item($this->_('Privacy'), 'privacy'));

        $this->getLayout()->placeholders()
            ->footer->append($menu->__toString());
    }
}
```

### Configuring Plugins

Plugins are registered in `environments.yml`:

```yaml
system:
  plugins:
    # Default plugins applied to ALL controllers
    default:
      http:                    # For HTTP requests
        - Locales              # Locale management
        - Vendor               # Vendor-specific translations
        - Auth                 # Authentication check
        - Layout\Page          # Page layout wrapper
        - Form                 # Form processing support
      cli:                     # For CLI commands
        - Vendor

    # Per-controller overrides
    groups:
      # Skip Auth for public controllers
      - controllers:
          - Index
          - Welcome
        http:
          skip:
            - Auth

      # Skip ALL plugins for system controllers
      - controllers:
          - System
        http:
          skip:
            - "*"
        cli:
          skip:
            - "*"

      # Replace layout for admin controllers
      - controllers:
          - Admin
        http:
          skip:
            - Layout\Page
          add:
            - Layout\Admin
            - Admin\Access
```

### Accessing Plugins from a Controller

```php
// Get plugin by symbol (defined in the plugin's SYMBOL constant)
$this->auth()->disable();
$this->auth()->authorizeAction('login');

// Disable layout
$this->layout()->disable();
```

---

## Services

Services are globally available objects registered in the container. They extend `Ovos\Service`.

### Built-in Services

| Service | Description |
|---------|-------------|
| `Events` | Error and exception handling |
| `Logger` | File-based error logging |
| `Benchmark` | Request performance measurement |
| `Session` | PHP session management (Redis-backed) |
| `Cookies` | HTTP cookie handling |
| `Cache` | Cache management (APCu + Redis) |
| `Memory` | APCu memory cache for configs/translations |

### Registering Services

Services are listed in `environments.yml`:

```yaml
system:
  services:
    container: \MyApp\Services   # Optional: custom Services class
    http:                         # Services for HTTP requests
      - Events
      - Logger
      - Session
      - Cookies
      - Cache
      - \MyApp\Service\Auth      # Custom service (fully qualified class name)
    cli:                          # Services for CLI commands
      - Events
      - Logger
      - Cache
```

### Custom Services Class

Override the Services class to add typed property access:

```php
<?php
declare(strict_types=1);

namespace MyApp;

use Ovos\Services as ParentServices;
use MyApp\Service\Auth;

use function Ovos\container;

/**
 * @property Auth $auth
 */
class Services extends ParentServices
{
}

function services(): Services
{
    return container()->get(ParentServices::class);
}
```

### Creating a Custom Service

```php
<?php
declare(strict_types=1);

namespace MyApp\Service;

use Ovos\Container\Inject;
use Ovos\Service;
use Ovos\Service\Session;
use Ovos\Service\Cookies;

class Auth extends Service
{
    public const string SYMBOL = 'auth';

    public function __construct(
        #[Inject(Session::SYMBOL)] protected Session $session,
        #[Inject(Cookies::SYMBOL)] protected Cookies $cookies,
    )
    {
    }

    public function hasUser(): bool
    {
        return $this->session->offsetExists('user_id');
    }
}
```

### Accessing Services

```php
// Via services() helper
$auth = services()->auth;

// Via container in controllers
$cache = $this->container->get(Cache::SYMBOL);
$session = $this->container->get(Session::SYMBOL);

// Via container anywhere
$auth = container()->get('auth');
```

---

## Dependency Injection (Container)

The framework uses a singleton DI container accessible via `container()`.

### Registration Methods

```php
use function Ovos\container;

$c = container();

// Register a class (lazy - instantiated on first get())
$c->registerClass('my_service', MyService::class, ['param1' => 'value']);

// Register and immediately get an instance
$instance = $c->getClass(MyService::class);

// Register a factory callable
$c->registerCallable('mailer', function() {
    return new Mailer(config()->smtp);
});

// Register an existing object instance
$c->registerObject('config', $configObject);

// Register a raw value
$c->registerValue('app_name', 'My Application');
```

### Constructor Injection

When a class is resolved from the container, its constructor parameters are automatically injected:

```php
class UserController extends Controller
{
    public function __construct(
        private UsersStore $store,          // Auto-resolved from container by type
        private Cache $cache,               // Auto-resolved from container by type
    )
    {
        parent::__construct();
    }
}
```

### Attribute-Based Injection

Use the `#[Inject]` attribute to inject dependencies by container key:

```php
use Ovos\Container\Inject;

class Auth extends Plugin
{
    public function __construct(
        #[Inject('auth')] ?AuthService $authService,    // By container key
        #[Inject(Session::SYMBOL)] Session $session,     // By service symbol
    )
    {
        parent::__construct();
    }
}
```

### Property Injection

Properties can also be injected using the `#[Inject]` attribute:

```php
class MyService extends Service
{
    #[Inject]
    protected Container $container;     // Auto-injected by type

    #[Inject]
    protected Application $app;         // Auto-injected by type

    #[Inject]
    protected Request $request;         // Auto-injected by type
}
```

### Using the Container in Controllers

Controllers have full access to the container via `$this->container`:

```php
class Dashboard extends Controller
{
    public function index(): Response
    {
        // Get a service by its symbol
        $cache = $this->container->get(Cache::SYMBOL);

        // Get or create an instance of a class
        $store = $this->container->getClass(UsersStore::class);

        // Get the config
        $config = $this->container->get('config');

        // Get custom registered service
        $mailer = $this->container->get('mailer');

        // ...
    }
}
```

### What's Registered by Default

The Application automatically registers these in the container:

| Key / Type | Object |
|------------|--------|
| `Application::class` | The Application singleton |
| `Request::class` | The current Request |
| `Router::class` | The Router |
| `Container::class` | The Container itself |
| `Environment::class` | The Environment |
| `'config'` | The configuration ArrayObject |
| `Services::class` | The Services manager |
| All service symbols | Each registered Service (e.g., `'cache'`, `'session'`, `'events'`) |

---

## Translations

The framework supports multi-language translation using GNU gettext `.mo` files and the `Translatable` trait.

### Setup

1. Define locales in `environments.yml`:

```yaml
system:
  locales:
    de:
      symbol: de_AT
      name: Deutsch
      language: de
      country: AT
      default: yes
    en:
      symbol: en_US
      name: English
      language: en
      country: US
```

2. Create a `phrases.php` registry in `application/translations/`:

```php
<?php
// Register all translatable phrases for extraction tools
_('Welcome');
_('Login');
_('Save & close');

// Plural forms
_n('One item', '{0} items', 0);
```

3. Create `.po` files for each language (e.g., `en.po`, `de.po`):

```po
msgid "Welcome"
msgstr "Willkommen"

msgid "Login"
msgstr "Anmelden"

# Parameterized translations use MessageFormatter syntax
msgid "Showing {0} to {1} out of {2}"
msgstr "Zeige {0} bis {1} von {2}"
```

4. Use [Poedit](https://poedit.net/) to edit `.po` files. Poedit can scan your application's source code directly to discover new translatable phrases and detect changes, and compiles `.mo` files automatically on save.

### Using Translations

In **controllers** (via `Translatable` trait):
```php
$message = $this->_('Welcome');
$message = $this->_n('One item', '{0} items', $count);
```

In **views** (via `Translatable` trait):
```php
<h1><?= $this->_('Welcome') ?></h1>
<p><?= $this->_('Showing {0} to {1} out of {2}', $from, $to, $total) ?></p>
```

In **plugins** (via `Translatable` trait):
```php
$label = $this->_('Contact');
```

### Vendor-Specific Translations

When `vendor` is set in config and a module has `vendor: true`, the system also looks for translations in:
```
application/translations/{vendor}/de.mo
```

This allows the same module to have different translations per project.

---

## Migrations

Database migrations track schema changes. They extend `Ovos\Migration`.

### Creating a Migration

Create a file in `application/migrations/` with timestamp naming:

```
application/migrations/20250101000000_CreateProducts.php
```

```php
<?php
declare(strict_types=1);

namespace Migrations;

use Ovos\Migration;

class CreateProducts extends Migration
{
    public function up(): void
    {
        $this->upSql();
    }

    public function down(): void
    {
        $this->downSql();
    }
}
```

Create the corresponding SQL files alongside the PHP file:

**`20250101000000_CreateProducts.up.sql`:**
```sql
CREATE TABLE products (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    price DECIMAL(10,2) NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    modified_at DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

**`20250101000000_CreateProducts.down.sql`:**
```sql
DROP TABLE IF EXISTS products;
```

### Organizing Migrations

You can organize migrations into subdirectories:
```
application/migrations/
├── Initial/
│   ├── 20210226000000_Struct.php
│   └── 20210226000000_Struct.up.sql
├── Updates_2024/
│   └── 20240101000000_AddUserRoles.php
└── Updates_2025/
    └── 20250101000000_CreateProducts.php
```

Register migration paths in config:
```yaml
system:
  migrations:
    - vendor/ovos/php-module-system/application/migrations
    - application/migrations
```

### Running Migrations

```bash
php cli.php migrations status              # Show migration status
php cli.php migrations run                  # Run all pending migrations
php cli.php migrations run 1               # Run next 1 migration
php cli.php migrations rollback             # Rollback last migration
php cli.php migrations rollback 3           # Rollback last 3 migrations
```

---

## Cache

The framework provides a two-tier cache system. For full details, see the dedicated guides:

- **[README.CACHE.md](README.CACHE.md)** - Cache stores, usage patterns, invalidation, and configuration
- **[README.MEMOLOCK.md](README.MEMOLOCK.md)** - Cache stampede protection (MemoLock)

**Two tiers:**

- **Perishable (APCu)**: Fast, per-worker memory cache. Lost on restart.
- **Persistent (Redis/Redisearch)**: Shared distributed cache with tag-based invalidation.

### Using Cache in Code

```php
$cacheService = $this->container->get(Cache::SYMBOL);

// Get the persistent store (Redis)
$store = $cacheService->getPersistent()->getStore();

// Get with resolver (recommended - handles cache misses)
$value = $store->get(
    'user:42',
    resolver: fn() => $this->fetchUserFromDB(42),
    ttl: 300,                    // 5 minutes
    tags: ['user', 'user:42'],   // For tag-based invalidation
);

// Invalidate by tag
$store->invalidateTags(['user:42']);

// Perishable cache (APCu)
$perishable = $cacheService->getPerishable()->getStore();
$value = $perishable->get('key', resolver: fn() => 'computed', ttl: 60);
```

### MemoLock (Cache Stampede Protection)

When many requests hit an expired cache key simultaneously, MemoLock ensures only one request computes the value while others wait. **MemoLock is enabled by default** - if you use `get()` with a resolver, you're already protected.

```php
$value = $store->get(
    'expensive_key',
    resolver: fn() => $this->expensiveComputation(),
    ttl: 60,
    queue: true,              // Enable queueing (default)
    queueLockTtlMs: 2000,    // Lock timeout
);
```

See [README.MEMOLOCK.md](README.MEMOLOCK.md) for advanced usage: manual lock control, lock renewal, error handling, and standalone mutex usage.

---

## CLI Commands

Commands provided by `php-module-system`:

```bash
# Cache management
php cli.php system cache clear              # Clear all cache
php cli.php system cache collect-garbage    # Force garbage collection

# Database migrations
php cli.php migrations status               # Show status
php cli.php migrations run                   # Run pending migrations
php cli.php migrations rollback              # Rollback last migration

# Tests
php cli.php tests run                        # Run all tests
php cli.php tests run MyTest                 # Run specific test class
php cli.php tests run MyTest myMethod        # Run specific test method

# Benchmarks
php cli.php benchmarks run                   # Run all benchmarks

# System
php cli.php system stats free-space          # Show disk space
php cli.php system collector                 # Run garbage collectors
php cli.php system sessions clear            # Clear sessions
php cli.php system tools encrypt "text"      # Encrypt a string
php cli.php system tools decrypt "cipher"    # Decrypt a string
```

---

## Useful Information

### Useful Redis Commands

```bash
docker exec -it redis-cache sh -c "redis-cli MONITOR"
docker exec -it redis-cache sh -c "redis-cli SLOWLOG GET 10"
docker exec -it redis-cache sh -c "redis-cli --latency"
docker exec -it redis-cache sh -c "redis-cli CONFIG GET timeout"
docker exec -it redis-cache sh -c "redis-cli CONFIG SET maxmemory-policy noeviction"
```

### Composer Scripts

Add these to your `composer.json` for convenience:

```json
{
  "scripts": {
    "cache:clear": "@php cli.php system cache clear",
    "migrations:run": "@php cli.php migrations run",
    "migrations:rollback": "@php cli.php migrations rollback",
    "test": "@php cli.php tests run",
    "prod:update": [
      "@composer install --no-dev --classmap-authoritative",
      "@migrations:run",
      "@cache:clear"
    ]
  }
}
```
