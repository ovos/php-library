<?php

namespace Tests\Session\Handler\RedisJson;

use Ovos\Application;
use Ovos\ArrayObject;
use Ovos\Connection\Redis as Connection;
use Ovos\Container\Inject;
use Ovos\Session\Handler\RedisJson;

use function Ovos\container;

use function define;
use function dirname;
use function is_dir;
use function usleep;

/**
 * Tool for testing the json session handler across processes
 *
 * Launched by tests/Session/Handler/RedisJson.php (see Parallel::run and
 * proc_open there) with two CLI arguments: a mode and a session id.
 *
 * - "writer": lock the "v" value, raise a flag key, keep the lock for
 *   700ms, then write "new" (which releases the lock and notifies)
 * - "increment": increment the "counter" value 20 times
 *
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

class Client
{
	public const string PREFIX = 'tests:sessions';
	
	#[Inject('config')]
	protected ArrayObject $config;
	
	public function run(): void
	{
		$mode = $_SERVER['argv'][1] ?? null;
		$sessionId = $_SERVER['argv'][2] ?? null;
		if($mode === null || $sessionId === null)
		{
			return;
		}
		
		$this->config->system->profilers->enabled = false;
		
		$connectionConfig = $this->config
			->getPath(['connections', 'redis']);
		$connection = new Connection($connectionConfig);
		if($connection->connect() === false)
		{
			return;
		}
		$queueConnection = new Connection($connectionConfig);
		
		// the lock ttl must match tests/Session/Handler/RedisJson.php
		$session = new RedisJson(
			$connection,
			$queueConnection,
			self::PREFIX,
			new ArrayObject([
				'lifetime' => 60,
				'lock' => [
					'enabled' => true,
					'lock_ttl_ms' => 3000,
					'wait_attempts' => 3,
				],
			]),
		);
		$session->open($sessionId);
		
		match($mode)
		{
			'writer' => $this->write($session, $connection),
			'increment' => $this->increment($session),
			default => null,
		};
	}
	
	protected function write(
		RedisJson $session,
		Connection $connection,
	): void
	{
		$session->getLocked(['v']);
		
		// signal the parent test that the lock is held
		$connection->getClient()->set(
			self::PREFIX . ':flag:' . $session->getSessionId(),
			'1',
			['EX' => 30],
		);
		
		// keep readers waiting, then write - set() releases and notifies
		usleep(700000);
		$session->set(['v'], 'new');
	}
	
	protected function increment(
		RedisJson $session,
	): void
	{
		for($i = 0; $i < 20; $i++)
		{
			$session->increment(['counter']);
		}
	}
}

$application = container()
	->getClass(Application::class); // register config before injecting
$client = $application->getContainer()
	->injectClass(Client::class);
$client->run();
