# README #

### GitHub
https://github.com/ovos/php-library

### Requirements

* PHP 8.1

### Suggested for usage as standalone application
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
