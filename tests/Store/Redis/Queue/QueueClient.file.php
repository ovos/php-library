<?php

namespace Ovos;

use Ovos\Redis\Connection;
use Ovos\Store\Cache;
use Ovos\Store\Redis;
use Ovos\Store\Redis\Cache as RedisCache;
use RedisException;

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

class QueueClient extends Controller\Cli
{
	/**
	 * @var string
	 */
	public const string KEY_ITEM = 'item2';
	
	/**
	 * @var string
	 */
	public const string KEY_ITEM_COUNTER = 'item:counter';
	
	/**
	 * @var ArrayObject
	 */
	protected ArrayObject $_config;
	
	/**
	 * @var Connection
	 */
	protected Connection $_connection;
	
	/**
	 * @var Redis
	 */
	protected Redis $_store;
	
	public function __construct()
	{
		parent::__construct();
		
		$this->_config = config()->cache;
		
		$this->_connection = new Connection($this->_config->persistent);
		if($this->_connection->connect() === false)
		{
			exit(0);
		}
		
		$this->_store = new Redis
		(
			$this->_config->prefix,
			$this->_connection,
			$this->_config->persistent,
			Cache::GROUP_TESTS,
		);
	}
	
	/**
	 * Run the queue client
	 */
	public function run(): void
	{
		$result = $this->_store->get(self::KEY_ITEM,
			setCallback: function(RedisCache $store)
			{
				$client = $store->getClient();
				if($client === null)
				{
					return;
				}
				$id = $store->prefix(self::KEY_ITEM_COUNTER, $store->getType());
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

$client = new QueueClient;
$client->run();