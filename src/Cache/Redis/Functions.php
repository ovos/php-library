<?php
declare(strict_types=1);

namespace Ovos\Cache\Redis;

use Ovos\Cache\Prefixer;
use Ovos\Connection\Redis as Connection;
use Redis as RedisClient;
use RedisException;

use function array_slice;
use function ceil;
use function count;
use function file_get_contents;
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
	
	public function getClient(): ?RedisClient
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
		
		if(isset($this->librariesLoaded[$libraryName])
			&& $this->librariesLoaded[$libraryName] === true
			&& $replace === false)
		{
			return true;
		}
		
		if(($client = $this->getClient()) === null)
		{
			return false;
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
		
		$functions = file_get_contents(__DIR__
			. DIRECTORY_SEPARATOR . 'Functions'
			. DIRECTORY_SEPARATOR . $libraryFile,
		);
		
		$functions = str_replace('[prefix]',
			$this->functionsPrefix
				? $this->functionsPrefix . static::SEPARATOR_FUNCTION
				: '',
			$functions,
		);
		
		$library = "#!lua name=" . $libraryName . PHP_EOL . PHP_EOL
			. $functions;
		
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
		
		// ensure that the library of scripts is loaded, if not load it into redis
		$this->loadLibraries();
		
		if($long)
		{
			// an extended timeout will be valid through all calls of the batch
			$this->connection
				->toggleReadTimeout(Connection::TIMEOUT_READ_LONG);
		}
		
		$call = $readOnly
			? 'fcall_ro'
			: 'fcall'
		;
		
		$functionName = $this->functionsPrefix($function);
		
		$result = $this->connection
			->slowLog(
				[$client, $call],
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
