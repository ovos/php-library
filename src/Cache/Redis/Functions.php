<?php
declare(strict_types=1);

namespace Ovos\Cache\Redis;

use Ovos\Cache\Prefixer;
use Ovos\Connection\RedisCommon as Connection;
use Redis as RedisClient;
use RedisCluster as RedisClusterClient;
use RedisException;

use function array_slice;
use function ceil;
use function count;
use function file_get_contents;
use function is_array;
use function is_file;
use function is_int;
use function str_replace;

use const PHP_EOL;
use const DIRECTORY_SEPARATOR;

/**
 * Functions
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Functions
{
	public const string SEPARATOR_FUNCTION = '_';
	
	public array $libraries;
	
	protected array $librariesLoaded = [];
	
	// cluster libraries are tracked per master node, not just per library: the
	// topology can gain or replace masters within a process lifetime and each
	// new master needs its own FUNCTION LOAD ([library][host:port] => true)
	protected array $clusterLibrariesLoaded = [];
	
	protected Connection $connection;
	
	protected ?string $functionsPrefix = null;
	
	public function __construct(
		array $libraries,
		Connection $connection,
		?string $prefix = null,
	)
	{
		$this->libraries = $libraries;
		$this->connection = $connection;
		
		$this->functionsPrefix = static::createFunctionsPrefix($prefix);
	}
	
	public function functionsPrefix(
		string $key,
		?string $prefix = null,
		string $separator = self::SEPARATOR_FUNCTION,
	): string
	{
		$prefix = $prefix ?? $this->functionsPrefix;
		
		return $prefix !== null
			? $prefix . $separator . $key
			: $key;
	}
	
	public static function createFunctionsPrefix(
		?string $prefix = null,
	): ?string
	{
		if($prefix === null)
		{
			return null;
		}
		
		return str_replace
		(
			[Prefixer::SEPARATOR_PREFIX, '-'],
			[self::SEPARATOR_FUNCTION, static::SEPARATOR_FUNCTION],
			$prefix,
		);
	}
	
	public function getClient(): RedisClient|RedisClusterClient|null
	{
		return $this->connection->getClient();
	}
	
	/**
	 * Ensures that all the libraries of scripts are loaded into redis
	 */
	public function loadLibraries(
		bool $replace = false,
	): bool
	{
		foreach($this->libraries as $libraryName => $libraryFile)
		{
			if($this->loadLibrary($libraryName, $libraryFile, $replace) === false)
			{
				return false;
			}
		}
		
		return true;
	}
	
	/**
	 * Ensures that a library of scripts is loaded into redis
	 * Library name and functions cannot use ":" character in their names (this includes also the prefix):
	 * "ERR Library names can only contain letters, numbers, or underscores(_) and must be at least one character"
	 */
	public function loadLibrary(
		string $libraryName,
		string $libraryFile,
		bool $replace = false,
	): bool
	{
		$libraryName = $this->functionsPrefix($libraryName);
		
		if(($client = $this->getClient()) === null)
		{
			return false;
		}
		
		// a cluster requires the library on every master node, and the topology
		// can change within a process lifetime (failover, resharding, scale-out);
		// a single per-library flag would skip the new masters, so the cluster
		// path tracks each master and tops up whoever is missing the library
		if($client instanceof RedisClusterClient)
		{
			return $this->loadLibraryCluster($client, $libraryName, $libraryFile, $replace);
		}
		
		// standalone is a single node - the per-library flag is enough
		if(isset($this->librariesLoaded[$libraryName])
			&& $this->librariesLoaded[$libraryName] === true
			&& $replace === false)
		{
			return true;
		}
		
		// if we force a replacement, no need to detect if a library is loaded
		if($replace === false)
		{
			$list = $client
				->function('list', 'libraryname', $libraryName);
			
			if($list !== false
				&& isset($list[0])
				&& $list[0]['library_name'] === $libraryName
			)
			{
				$this->librariesLoaded[$libraryName] = true;
				
				return true;
			}
		}
		
		$client->clearLastError();
		
		$library = $this->buildLibrary($libraryName, $libraryFile);
		
		$libraryLoaded = $replace
			? $client
				->function('load', 'replace', $library)
			: $client
				->function('load', $library);
		
		if($error = $client->getLastError())
		{
			throw new RedisException($error);
		}
		
		if($libraryLoaded === $libraryName)
		{
			$this->librariesLoaded[$libraryName] = true;
			
			return true;
		}
		
		return false;
	}
	
	/**
	 * Loads a library of scripts on every master node of a cluster,
	 * FUNCTION LOAD only affects the node it is sent to
	 */
	protected function loadLibraryCluster(
		RedisClusterClient $client,
		string $libraryName, // already prefixed
		string $libraryFile,
		bool $replace = false,
	): bool
	{
		$library = null;
		$loaded = true;
		
		foreach($client->_masters() as $master)
		{
			$masterKey = $master[0] . ':' . $master[1];
			
			// already loaded on this master in this process: skip the network
			// round trip (a forced replacement always re-uploads)
			if($replace === false
				&& isset($this->clusterLibrariesLoaded[$libraryName][$masterKey]))
			{
				continue;
			}
			
			// a master we have not loaded yet (first seen, or new to the
			// topology): only upload when it is actually missing the library
			if($replace === false
				&& $this->isLibraryOnNode($client, $master, $libraryName))
			{
				$this->clusterLibrariesLoaded[$libraryName][$masterKey] = true;
				
				continue;
			}
			
			// build the library source only when a node needs it
			$library ??= $this->buildLibrary($libraryName, $libraryFile);
			
			$client->clearLastError();
			
			$libraryLoaded = $replace
				? $client->rawCommand($master,
					'FUNCTION', 'LOAD', 'REPLACE', $library)
				: $client->rawCommand($master,
					'FUNCTION', 'LOAD', $library);
			
			if($error = $client->getLastError())
			{
				throw new RedisException($error);
			}
			
			if($libraryLoaded !== $libraryName)
			{
				// this master did not confirm the load; keep going so the rest
				// still get the library, but report the partial result - callers
				// (Functions::call) bail rather than FCALL a node still missing it
				$loaded = false;
				
				continue;
			}
			
			$this->clusterLibrariesLoaded[$libraryName][$masterKey] = true;
		}
		
		return $loaded;
	}
	
	/**
	 * Detects if a library of scripts is loaded on a cluster node
	 *
	 * The shape of the FUNCTION LIST reply depends on who parsed it:
	 * rawCommand returns the RESP2 wire format, where a map arrives as a
	 * flat [field, value, field, value, ...] array; a command-aware parser
	 * returns an associative array instead - that is what the dedicated
	 * function() method produces on the standalone client, and what
	 * rawCommand itself would produce under RESP3 or the Relay client.
	 * Detection accepts both shapes on purpose: phpredis 6.3 only speaks
	 * RESP2 (flat), but a shape change after a client upgrade would not
	 * error here - it would silently fail the detection and re-upload the
	 * library on every request (or throw "Library already exists").
	 */
	protected function isLibraryOnNode(
		RedisClusterClient $client,
		array $master,
		string $libraryName, // already prefixed
	): bool
	{
		$list = $client->rawCommand($master,
			'FUNCTION', 'LIST', 'LIBRARYNAME', $libraryName);
		
		if(is_array($list) === false)
		{
			return false;
		}
		
		foreach($list as $library)
		{
			if(is_array($library) === false)
			{
				continue;
			}
			
			// shape 1: an associative array (command-aware parsed reply) -
			// unreachable with phpredis 6.3 rawCommand, see the docblock
			if(isset($library['library_name']))
			{
				if($library['library_name'] === $libraryName)
				{
					return true;
				}
				
				continue;
			}
			
			// shape 2: the raw RESP2 flat map - the value at $index + 1
			// follows its field name; is_int() keeps the index arithmetic
			// away from string keys, should an associative reply without
			// a "library_name" field ever fall through to this loop
			foreach($library as $index => $field)
			{
				if(is_int($index)
					&& $field === 'library_name'
					&& ($library[$index + 1] ?? null) === $libraryName)
				{
					return true;
				}
			}
		}
		
		return false;
	}
	
	/**
	 * Builds a library of scripts from a file, applying the functions prefix
	 * A bare filename is resolved against this directory's "Functions";
	 * callers outside the cache (e.g. the json session handler) pass a
	 * full path to their own library file instead
	 */
	protected function buildLibrary(
		string $libraryName, // already prefixed
		string $libraryFile,
	): string
	{
		if(is_file($libraryFile) === false)
		{
			$libraryFile = __DIR__
				. DIRECTORY_SEPARATOR . 'Functions'
				. DIRECTORY_SEPARATOR . $libraryFile;
		}
		
		$functions = file_get_contents($libraryFile);
		
		$functions = str_replace('[prefix]',
			$this->functionsPrefix
				? $this->functionsPrefix . static::SEPARATOR_FUNCTION
				: '',
			$functions,
		);
		
		return "#!lua name=" . $libraryName . PHP_EOL . PHP_EOL
			. $functions;
	}
	
	public function call(
		string $function,
		array $keys = [],
		array $args = [],
		bool $readOnly = false,
		bool $long = false,
	): mixed
	{
		if(($client = $this->getClient()) === null)
		{
			return false;
		}
		
		// ensure the library of scripts is loaded; bail out if a node could not
		// be (re)loaded rather than issue an FCALL against one that is missing it
		if($this->loadLibraries() === false)
		{
			return false;
		}
		
		if($long)
		{
			// an extended timeout will be valid through all calls of the batch
			$this->connection
				->toggleReadTimeout(Connection::TIMEOUT_READ_LONG);
		}
		
		$functionName = $this->functionsPrefix($function);
		
		// phpredis marshals integer arguments through a platform "long"
		// (32 bits on Windows) on both the cluster rawCommand and the
		// standalone fcall paths - a value like a 30-day millisecond
		// retention silently truncates to a negative number; the protocol
		// is strings anyway, so send strings on either path
		foreach($args as $index => $arg)
		{
			$args[$index] = (string)$arg;
		}
		
		// phpredis RedisCluster has no fcall()/fcall_ro() methods,
		// route the call by the first key instead (a single-key call
		// is executed by the node owning the key's hash slot)
		if($client instanceof RedisClusterClient)
		{
			$call = function(string $function, array $keys, array $args)
				use ($client, $readOnly): mixed
			{
				return $client->rawCommand(
					$keys[0] ?? $client->_masters()[0],
					$readOnly ? 'FCALL_RO' : 'FCALL',
					$function,
					(string)count($keys),
					...$keys,
					...$args,
				);
			};
		}
		else
		{
			$call = [$client, $readOnly
				? 'fcall_ro'
				: 'fcall'
			];
		}
		
		$result = $this->connection
			->slowLog(
				$call,
				$functionName,
				$keys,
				$args,
			);
		
		if($long)
		{
			$this->connection
				->toggleReadTimeout();
		}
		
		return $result;
	}
	
	public function batchCall(
		string $function,
		array $keys = [],
		array $args = [],
		bool $readOnly = false,
		bool $long = false,
		int $batchSize = 1000,
	): void
	{
		if($long)
		{
			// an extended timeout will be valid through all calls of the batch
			$this->connection
				->toggleReadTimeout(Connection::TIMEOUT_READ_LONG);
		}
		
		$countKeys = count($keys);
		$totalBatches = (int)ceil($countKeys / $batchSize);
		
		for($batch = 0; $batch < $totalBatches; $batch++)
		{
			$keysBatch = array_slice($keys, $batch * $batchSize, $batchSize);
			$this->call($function, $keysBatch, $args, $readOnly);
		}
		
		if($long)
		{
			$this->connection
				->toggleReadTimeout();
		}
	}
}
