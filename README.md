# README #

### GitHub
https://github.com/ovos/php-library

###
Use this method to workaround https://github.com/php-cache/issues/issues/144
```
composer require cache/hierarchical-cache:"1.1.0 as 0.4" cache/prefixed-cache
```

### Requirements

* PHP 8.0
* apcu
* yaml
* redis

#### Installation
```
"repositories": [
{
  "type": "vcs",
  "url": "git@github.com:ovos/php-library.git"
}
],
```

```
git require ovos/php-library
```
