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
use RedisException;
use stdClass;

use function apcu_delete;
use function array_key_first;
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
