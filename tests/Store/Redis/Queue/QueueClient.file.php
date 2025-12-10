<?php

namespace Tests\Store\Redis\Queue;

use Ovos\Application;
use Ovos\ArrayObject;
use Ovos\Container\Inject;
use Ovos\Controller;
use Ovos\Store\KeyValue;
use Ovos\Store\Redis as Store;
use Ovos\Test\Store\TraitRedis;

use function define;
use function dirname;
use function getmypid;
use function is_dir;
use function microtime;
use function sleep;
use function Ovos\container;

/**
 * Tool for testing cache queue
 *
 * @package Tools
 * @author Marcin Gil <mg@ovos.at>
 */

// define base dir
define('BASE_DIR', dirname(__DIR__, 7) . DIRECTORY_SEPARATOR);

// include bootstrap initialization
require_once BASE_DIR . 'init.php';

// define custom configs dir for cases where the tests are not run 
// within the application, but, for example, in the CI
if(is_dir(Application::CONFIGS_DIR) === false)
{
	define('CONFIGS_DIR', BASE_DIR);
}

class QueueClient extends Controller\Cli
{
	use TraitRedis;
	
	public const string KEY_ITEM = 'item';
	
	public const string KEY_ITEM_COUNTER = 'item:counter';
	
	#[Inject('config')]
	protected ArrayObject $config;
	
	protected Store $store;
	
	public function __construct()
	{
		parent::__construct();
		
		$this->config->system->profilers->enabled = false;
		
		$this->group = $_SERVER['argv'][1] ?? KeyValue::GROUP_TESTS;
		$this->store = $this->getStore(Store::class);
	}
	
	/**
	 * Run the queue client
	 */
	public function run(): void
	{
		$result = $this->store->get(self::KEY_ITEM,
			resolver: function(Store $store)
			{
				$client = $store->getClient();
				if($client === null)
				{
					return;
				}
				
				sleep(1);
				
				$id = $store->prefix(self::KEY_ITEM_COUNTER,
					$store->getType()
				);
				
				$client->incr($id);
				$client->expire($id, 30);
				
				$value = [
					'pid' => getmypid(),
					'time' => microtime(true),
				];
				$store->set(self::KEY_ITEM, $value, 30);
			},
		);
	}
}

$application = container()
	->getClass(Application::class); // register config before injecting
$client = $application->getContainer()
	->injectClass(QueueClient::class);
$client->run();