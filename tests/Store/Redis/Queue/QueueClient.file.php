<?php

namespace Tests\Store\Redis\Queue;

use Ovos\Application;
use Ovos\ArrayObject;
use Ovos\Container\Inject;
use Ovos\Container\Injector\TypeClass;
use Ovos\Controller;
use Ovos\Store\KeyValue;
use Ovos\Store\Redis as Store;
use Ovos\Test\Store\TraitRedis;

use function dirname;
use function define;
use function is_dir;
use function getmypid;
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
	
	/**
	 * @var string
	 */
	public const string KEY_ITEM = 'item';
	
	/**
	 * @var string
	 */
	public const string KEY_ITEM_COUNTER = 'item:counter';
	
	/**
	 * @var ArrayObject
	 */
	#[Inject('config')]
	protected ArrayObject $_config;
	
	/**
	 * @var Store
	 */
	protected Store $_store;
	
	public function __construct()
	{
		parent::__construct();
		
		$this->_config->system->profilers->enabled = false;
		
		$this->_group = $_SERVER['argv'][1] ?? KeyValue::GROUP_TESTS;
		$this->_store = $this->_getStore(Store::class);
	}
	
	/**
	 * Run the queue client
	 */
	public function run(): void
	{
		$result = $this->_store->get(self::KEY_ITEM,
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
	->inject(new TypeClass(QueueClient::class));
$client->run();