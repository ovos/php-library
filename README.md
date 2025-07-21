# README #

### GitHub
https://github.com/ovos/php-library

### Requirements

* PHP 8.3

### Suggested for usage as a standalone application
* apcu
* yaml
* redis
* simplexml

#### Installation
1. Add to your .ssh/config
```
Host ovos.php-library
    HostName github.com
    PreferredAuthentications publickey
    IdentityFile ~/.ssh/ovos.php-library
```
2. Add to composer.json
```
"repositories": [
{
  "type": "vcs",
  "url": "git@github.com:ovos/php-library.git"
}
],
```
3.
```
git require ovos/php-library
```

### CLI commands
#### Clear cache
* `php cli.php system cache clear`  
clears all types of active cache
#### Stats
* `php cli.php system stats free-space`  
displays free space on server
#### Collectors (garbage, logs)
* `php cli.php system collector`  
invokes all configured (in config) collectors
#### Migrations
* `php cli.php migrations run`  
runs migrations  
* `php cli.php migrations rollback`  
rollbacks migrations
#### Tests
* `php cli.php tests run`  
runs tests
#### Benchmarks
* `php cli.php benchmarks run`  
runs benchmarks

### Useful information

#### Useful redis commands
```
docker exec -it redis-cache sh -c "redis-cli MONITOR"
docker exec -it redis-cache sh -c "redis-cli slowlog get 10"
docker exec -it redis-cache sh -c "redis-cli --latency"
docker exec -it redis-cache sh -c "redis-cli CONFIG GET timeout"
docker exec -it redis-cache sh -c "redis-cli CONFIG get maxmemory"
docker exec -it redis-cache sh -c "redis-cli config SET maxmemory-policy noeviction"
docker exec -it redis-cache sh -c "redis-cli config maxmemory-policy noeviction"
docker run -d --name redis-stack-server -p 6379:6379 --env REDIS_ARGS="--maxmemory-policy volatile-ttl" redis/redis-stack-server:latest
docker run -d --name redis-cache -p 6380:6379 -p 8001:8001 --env REDIS_ARGS="--maxmemory-policy volatile-ttl" redis/redis-stack-server:latest
```

