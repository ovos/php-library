<?php

namespace Tests\Cache\Store\MemoLock\RedisCluster;

use Ovos\Application;
use Ovos\Cache\Store\KeyValue\Redis as KeyValueRedis;
use Ovos\Test\Cache\Store\MemoLock\QueueClient;
use Ovos\Test\Cache\Store\TraitRedisCluster;

use function Ovos\container;

use function define;
use function dirname;
use function is_dir;

/**
 * Tool for testing cache queue against a Redis Cluster
 *
 * @author Marcin Gil <mg@ovos.at>
 */

// define base dir
define('BASE_DIR', dirname(__DIR__, 8) . DIRECTORY_SEPARATOR);

// include bootstrap initialization
require_once BASE_DIR . 'init.php';

// define custom configs dir for cases where the tests are not run
// within the application, but, for example, in the CI
if(is_dir(Application::CONFIGS_DIR) === false)
{
	define('CONFIGS_DIR', BASE_DIR);
}

class Client extends QueueClient
{
	use TraitRedisCluster;
	
	protected function createStore(): ?KeyValueRedis
	{
		return $this->getClusterStore();
	}
}

$application = container()
	->getClass(Application::class); // register config before injecting
$client = $application->getContainer()
	->injectClass(Client::class);
$client->run();
