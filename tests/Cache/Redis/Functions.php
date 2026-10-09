<?php
declare(strict_types=1);

namespace Tests\Cache\Redis;

use APCUIterator;
use Error;
use Ovos\Cache\Redis\Functions as Subject;
use Ovos\Cache\Store\RedisVersioned as Store;
use Ovos\Test;
use Ovos\Test\Cache\Store\TraitRedisCluster;
use Ovos\Test\Exception\SkipException;
use Override;
use Redis;
use RedisCluster;
use RedisException;
use stdClass;

use function apcu_delete;
use function array_key_first;
use function array_map;
use function array_unique;
use function bin2hex;
use function preg_quote;
use function random_bytes;

/**
 * Functions
 *
 * The Lua libraries' loader and caller
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Functions extends Test
{
	use TraitRedisCluster;
	
	/**
	 * The busy-reply-threshold a long call must leave as it found it (ms)
	 */
	protected const string BUSY_THRESHOLD = '4321';
	
	/**
	 * RULE: a library another process loaded between this one's look and its
	 * load is no error - the load replaces it with the same source, it never
	 * fails on "already exists"
	 */
	public function aLibraryLoadedMeanwhileElsewhereIsNoError(): bool
	{
		$store = $this->getStore(Store::class);
		$libraries = $store->getFunctions()->libraries;
		$name = array_key_first($libraries);
		$functions = new class([$name => $libraries[$name]], $store->getConnection(), 'tests_' . bin2hex(random_bytes(4))) extends Subject
		{
			#[Override]
			protected function buildLibrary(
				string $libraryName,
				string $libraryFile,
			): string
			{
				$library = parent::buildLibrary($libraryName, $libraryFile);
				// another process loads it after this one looked
				$this->getClient()->function('load', $library);
				
				return $library;
			}
		};
		
		try
		{
			$loaded = $functions->loadLibraries();
		}
		catch(RedisException)
		{
			$loaded = false;
		}
		finally
		{
			$store->getClient()->function('delete', $functions->functionsPrefix($name));
		}
		
		return $loaded === true;
	}
	
	/**
	 * RULE: a library one process confirmed on this server is neither listed
	 * nor built again by the next (APCu) - every request paid a FUNCTION LIST
	 * per library before
	 */
	public function aLibraryConfirmedOnThisServerIsNotCheckedAgainByTheNextProcess(): bool
	{
		$store = $this->getStore(Store::class);
		$libraries = $store->getFunctions()->libraries;
		$name = array_key_first($libraries);
		$prefix = 'tests_' . bin2hex(random_bytes(4));
		$first = new Subject([$name => $libraries[$name]], $store->getConnection(), $prefix);
		$second = $this->countingBuilds([$name => $libraries[$name]], $store, $prefix);
		
		try
		{
			$first->loadLibraries();
			
			return $second->loadLibraries() === true
				&& $second->builds === 0;
		}
		finally
		{
			$store->getClient()->function('delete', $first->functionsPrefix($name));
		}
	}
	
	/**
	 * RULE: a library a process found loaded (by its LIST) is confirmed as
	 * one it loaded is - the next process does not check it again
	 */
	public function aLibraryFoundByItsListIsConfirmedForTheNextProcess(): bool
	{
		$store = $this->getStore(Store::class);
		$libraries = $store->getFunctions()->libraries;
		$name = array_key_first($libraries);
		$prefix = 'tests_' . bin2hex(random_bytes(4));
		$first = new Subject([$name => $libraries[$name]], $store->getConnection(), $prefix);
		
		try
		{
			$first->loadLibraries();
			// loaded on the server, no confirmation left on this host
			apcu_delete(new APCUIterator('/^' . preg_quote(Subject::KEY_CONFIRMED, '/') . '/'));
			(new Subject([$name => $libraries[$name]], $store->getConnection(), $prefix))->loadLibraries();
			$third = $this->countingBuilds([$name => $libraries[$name]], $store, $prefix);
			
			return $third->loadLibraries() === true
				&& $third->builds === 0;
		}
		finally
		{
			$store->getClient()->function('delete', $first->functionsPrefix($name));
		}
	}
	
	/**
	 * RULE: a cluster confirms the library per master - the next process
	 * neither lists nor builds it where it was confirmed lately, and a
	 * library a process found on the masters by their LISTs is confirmed
	 * there too
	 */
	public function aClusterConfirmsTheLibraryPerMaster(): bool
	{
		$store = $this->getClusterStore();
		if($store === null)
		{
			throw new SkipException((string)$this->clusterUnavailableReason);
		}
		
		$libraries = [];
		$name = array_key_first($store->getFunctions()->libraries);
		$libraries[$name] = $store->getFunctions()->libraries[$name];
		$prefix = 'tests_' . bin2hex(random_bytes(4));
		$first = $this->countingBuilds($libraries, $store, $prefix);
		
		try
		{
			$first->loadLibraries();
			$second = $this->countingBuilds($libraries, $store, $prefix);
			$confirmed = $second->loadLibraries() === true
				&& $second->builds === 0;
			// loaded on the masters, no confirmation left on this host
			apcu_delete(new APCUIterator('/^' . preg_quote(Subject::KEY_CONFIRMED, '/') . '/'));
			$this->countingBuilds($libraries, $store, $prefix)
				->loadLibraries();
			$third = $this->countingBuilds($libraries, $store, $prefix);
			
			return $confirmed
				&& $third->loadLibraries() === true
				&& $third->builds === 0;
		}
		finally
		{
			$client = $store->getClient();
			foreach($client->_masters() as $master)
			{
				$client->rawCommand($master, 'FUNCTION', 'DELETE', $first->functionsPrefix($name));
			}
		}
	}
	
	/**
	 * RULE: a library gone from the server under a confirmation (a FUNCTION
	 * FLUSH, a restart without persistence) is reloaded by the next call that
	 * misses it - the confirmation never hides a missing library
	 */
	public function aLibraryGoneUnderAConfirmationIsReloadedOnTheNextCall(): bool
	{
		$store = $this->getStore(Store::class);
		$prefix = 'tests_' . bin2hex(random_bytes(4));
		$key = 'tests:functions:' . bin2hex(random_bytes(4));
		$first = new Subject(['cache' => 'Cache.lua'], $store->getConnection(), $prefix);
		$first->loadLibraries();
		$store->getClient()->function('delete', $first->functionsPrefix('cache'));
		
		$second = new Subject(['cache' => 'Cache.lua'], $store->getConnection(), $prefix);
		
		try
		{
			return $second->call('cache_stamp', [$key], [1000]) !== false
				&& $store->getClient()->exists($key) === 1;
		}
		finally
		{
			$store->getClient()->del($key);
			$store->getClient()->function('delete', $first->functionsPrefix('cache'));
		}
	}
	
	/**
	 * RULE: a long call that throws gives the read timeout back - the long
	 * one never outlives the call
	 */
	/**
	 * A Functions that counts the library sources it builds
	 */
	/**
	 * A long call on $store: the read timeout before, during and after it,
	 * and the server's busy-reply-threshold after it (on every master of a
	 * cluster) - set to BUSY_THRESHOLD first, the old value restored after
	 *
	 * @return array{0: mixed, 1: mixed, 2: mixed, 3: list<string>}
	 */
	protected function longCall(
		Store $store,
	): array
	{
		$client = $store->getClient();
		$nodes = $client instanceof RedisCluster
			? $client->_masters()
			: [null];
		$config = static fn(?array $node, string ...$args): mixed => $node === null
			? $client->config(...$args)
			: $client->config($node, ...$args);
		// Redis answers CONFIG GET with a map, RedisCluster with the flat pair
		$thresholds = static fn(): array => array_map(
			static fn(?array $node): string => (string)(($reply = $config($node, 'GET', 'busy-reply-threshold'))['busy-reply-threshold'] ?? $reply[1]),
			$nodes,
		);
		$original = $thresholds();
		foreach($nodes as $node)
		{
			$config($node, 'SET', 'busy-reply-threshold', self::BUSY_THRESHOLD);
		}
		// records the read timeout functionsPrefix() sees - call() asks for
		// the function's name after the long timeout is set
		$functions = new class(['cache' => $store->getFunctions()->libraries['cache']], $store->getConnection(), 'tests_' . bin2hex(random_bytes(4))) extends Subject
		{
			/** @var array<string, mixed> */
			public array $readTimeouts = [];
			
			#[Override]
			public function functionsPrefix(
				string $key,
				?string $prefix = null,
				string $separator = self::SEPARATOR_FUNCTION,
			): string
			{
				$this->readTimeouts[$key] = $this->getClient()
					->getOption(Redis::OPT_READ_TIMEOUT);
				
				return parent::functionsPrefix($key, $prefix, $separator);
			}
		};
		$key = 'tests:functions:' . bin2hex(random_bytes(4));
		$before = $client->getOption(Redis::OPT_READ_TIMEOUT);
		
		try
		{
			$functions->call('cache_stamp', [$key], [1000], long: true);
			
			return [
				$before,
				$functions->readTimeouts['cache_stamp'] ?? null,
				$client->getOption(Redis::OPT_READ_TIMEOUT),
				$thresholds(),
			];
		}
		finally
		{
			$client->del($key);
			$library = $functions->functionsPrefix('cache');
			foreach($nodes as $index => $node)
			{
				$config($node, 'SET', 'busy-reply-threshold', $original[$index]);
				if($node === null)
				{
					$client->function('delete', $library);
				}
				else
				{
					$client->rawCommand($node, 'FUNCTION', 'DELETE', $library);
				}
			}
		}
	}
	
	protected function countingBuilds(
		array $libraries,
		Store $store,
		string $prefix,
	): Subject
	{
		return new class($libraries, $store->getConnection(), $prefix) extends Subject
		{
			public int $builds = 0;
			
			#[Override]
			protected function buildSource(
				string $libraryFile,
			): string
			{
				$this->builds++;
				
				return parent::buildSource($libraryFile);
			}
		};
	}
	
	/**
	 * RULE: a long call waits for its script with the long read timeout and
	 * gives the normal one back, and it leaves the server's
	 * busy-reply-threshold alone - that setting only decides what other
	 * clients get meanwhile (a BUSY reply, or a wait), never the caller's
	 * reply; the call used to set it server-wide, and left it below the
	 * server's own value afterwards
	 */
	public function aLongCallWaitsLongerAndLeavesTheServerAlone(): bool
	{
		[$before, $during, $after, $thresholds] = $this->longCall($this->getStore(Store::class));
		
		return $during > $before
			&& $after === $before
			&& $thresholds === [self::BUSY_THRESHOLD];
	}
	
	/**
	 * RULE: on a cluster the long call leaves every master's
	 * busy-reply-threshold alone
	 */
	public function aLongCallLeavesEveryMasterAlone(): bool
	{
		$store = $this->getClusterStore();
		if($store === null)
		{
			throw new SkipException((string)$this->clusterUnavailableReason);
		}
		
		[$before, $during, $after, $thresholds] = $this->longCall($store);
		
		return $during > $before
			&& $after === $before
			&& $thresholds !== []
			&& array_unique($thresholds) === [self::BUSY_THRESHOLD];
	}
	
	public function aThrowingLongCallRestoresTheTimeout(): bool
	{
		$store = $this->getStore(Store::class);
		$client = $store->getClient();
		$before = $client->getOption(Redis::OPT_READ_TIMEOUT);
		
		try
		{
			// an argument that is no string throws after the timeout switched
			$store->getFunctions()->call('cache_versioned_drop_stale', ['tests:none'], [new stdClass], long: true);
			
			return false;
		}
		catch(Error)
		{
		}
		
		return $client->getOption(Redis::OPT_READ_TIMEOUT) === $before;
	}
}
