<?php
declare(strict_types=1);

namespace Tests\Cache\Store\RedisVersioned;

use Ovos\Application;
use Ovos\ArrayObject;
use Ovos\Container\Inject;
use Ovos\Controller;
use Ovos\Test\Cache\Store\RedisVersionedProbe;
use Ovos\Test\Cache\Store\SharedRulesInFiles;
use Ovos\Test\Cache\Store\SharedRulesProbe;
use Ovos\Test\Cache\Store\TraitRedis;
use RedisException;

use function count;
use function define;
use function dirname;
use function file_put_contents;
use function ini_set;
use function is_dir;
use function json_encode;
use function memory_get_usage;
use function microtime;
use function Ovos\container;
use function str_repeat;
use function usleep;

use const DIRECTORY_SEPARATOR;

/**
 * One worker of a server, as a process of its own, for the rules of
 * Tests\Cache\Store\RedisVersioned that need a reload in flight, hanging or
 * dying in another process: the workers share the rule set, and its flag,
 * through one directory (SharedRulesInFiles). At the moment it is given it
 * reads item1 once, its rules fetch answering late, failing or dying as
 * told, and leaves what it did in report-<name>.json in that directory.
 *
 *   php Worker.file.php <group> <directory> <name> <start at> <rules_cache_ms> <read|die> <fetch delay ms> <fetch fails: 0|1>
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

class Worker extends Controller\Cli
{
	use TraitRedis;
	
	#[Inject('config')]
	protected ArrayObject $config;
	
	public function run(): void
	{
		$this->config->system->profilers->enabled = false;
		
		$arguments = $_SERVER['argv'];
		$group = (string)($arguments[1] ?? '');
		$directory = (string)($arguments[2] ?? '');
		$name = (string)($arguments[3] ?? '');
		$startAt = (float)($arguments[4] ?? 0);
		$mode = (string)($arguments[6] ?? 'read');
		$delayMs = (int)($arguments[7] ?? 0);
		$fails = ($arguments[8] ?? '0') === '1';
		
		/** @var RedisVersionedProbe $probe */
		$probe = $this->getStore(RedisVersionedProbe::class, ['rules_cache_ms' => (int)($arguments[5] ?? 0)]);
		$probe->setGroup($group);
		$probe->sharedRulesFactory = static fn(string $rulesKey, string $identity): SharedRulesProbe => new SharedRulesInFiles($rulesKey, $identity, $directory);
		$probe->beforeRuleFetch = static function() use ($mode, $delayMs, $fails, $directory, $name): void
		{
			// a fatal error, which no finally sees: past a limit just above what
			// the process holds, once it says it got this far
			if($mode === 'die')
			{
				file_put_contents($directory . DIRECTORY_SEPARATOR . 'fetching-' . $name, '1');
				ini_set('memory_limit', (string)(memory_get_usage(true) + 8 * 1024 * 1024));
				$grow = [];
				for($i = 0; $i < 1024; $i++)
				{
					$grow[] = str_repeat('x', 1 << 20);
				}
			}
			
			usleep($delayMs * 1000);
			if($fails)
			{
				throw new RedisException('the rules node does not answer');
			}
		};
		// connected before the moment, so every worker reads at it
		$probe->getClient();
		
		$wait = $startAt - microtime(true);
		if($wait > 0)
		{
			usleep((int)($wait * 1000000));
		}
		
		$began = microtime(true);
		$value = $probe->get('item1', queue: false);
		
		file_put_contents($directory . DIRECTORY_SEPARATOR . 'report-' . $name . '.json', json_encode([
			'value' => $value,
			'attempts' => count($probe->fetchedFrom),
			'ms' => (microtime(true) - $began) * 1000,
		]));
	}
}

$application = container()
	->getClass(Application::class); // register config before injecting
$worker = $application->getContainer()
	->injectClass(Worker::class);
$worker->run();
