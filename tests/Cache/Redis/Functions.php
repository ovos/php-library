<?php
declare(strict_types=1);

namespace Tests\Cache\Redis;

use Error;
use Ovos\Cache\Redis\Functions as Subject;
use Ovos\Cache\Store\RedisVersioned as Store;
use Ovos\Test;
use Ovos\Test\Cache\Store\TraitRedis;
use Override;
use Redis;
use RedisException;
use stdClass;

use function array_key_first;
use function bin2hex;
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
	use TraitRedis;
	
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
	 * RULE: a long call that throws gives the read timeout back - the long
	 * one never outlives the call
	 */
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
