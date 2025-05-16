<?php
declare(strict_types=1);

namespace Ovos\Store;

use Ovos\ArrayObject;
use Ovos\Redis\Connection;
use Redis as BaseRedis;
use RedisException;

use function is_int;
use function count;
use function explode;
use function implode;
use function file_get_contents;
use function ceil;
use function array_push;
use function array_slice;
use function array_unique;
use function array_merge;
use function array_diff;

/**
 * Redis
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
class Redis extends Cache
{
	/**#@+
	 * Keys
	 */
	public const string KEY_DATA = 'data';
	public const string KEY_TAGS = 'tags';
	/**#@-*/
	
	/**#@+
	 * Statuses
	 * Used for rawCommand, which returns strings instead of boolean values when OPT_REPLY_LITERAL is enabled
	 * @see https://github.com/phpredis/phpredis/issues/1550
	 */
	public const string STATUS_OK = 'OK';
	/**#@-*/
	
	/**
	 * Types
	 */
	public const string TYPE_ITEMS = 'items';
	public const string TYPE_TAGS = 'tags';
	/**#@-*/
	
	/**
	 * Redis connection
	 *
	 * @var Connection
	 */
	protected Connection $_connection;
	
	/**
	 * @var int
	 */
	protected int $_multiMode = BaseRedis::PIPELINE;
	
	/**
	 * @var bool
	 */
	protected bool $_cleanTags = false;
	
	/**#@+
	 * Libraries
	 */
	/**
	 * The array of function libraries used by this lass
	 */
	public const array LIBRARIES = [
		'cache' => 'Lua' . DIRECTORY_SEPARATOR . 'Cache.lua',
	];
	/**#@-*/
	
	/**
	 * @var array
	 */
	protected array $_librariesLoaded = [];
	
	/**
	 * @param string $prefix
	 * @param Connection $connection
	 * @param ArrayObject $config
	 * @param ?string $group
	 */
	public function __construct
	(
		string $prefix,
		Connection $connection,
		ArrayObject $config,
		?string $group = null,
	)
	{
		parent::__construct();
		
		$this->setPrefix($prefix);
		$this->setConnection($connection);
		$this->setConfig($config);
		$this->setGroup($group);
		
		if($storeOptions = $this->_config->offsetGet('store_options'))
		{
			$this->setStoreOptions($storeOptions);
		}
	}
	
	/**
	 * @param ArrayObject $options
	 *
	 * @return self
	 */
	public function setStoreOptions(ArrayObject $options): self
	{
		if($options->offsetGet('clean_tags'))
		{
			$this->_cleanTags = true;
		}
		
		return $this;
	}
	
	/**
	 * @param Connection $connection
	 *
	 * @return self
	 */
	public function setConnection(Connection $connection): self
	{
		$this->_connection = $connection;
		
		return $this;
	}
	
	/**
	 * @return Connection
	 */
	public function getConnection(): Connection
	{
		return $this->_connection;
	}
	
	/**
	 * @return ?BaseRedis
	 */
	public function getClient(): ?BaseRedis
	{
		return $this->_connection->getClient();
	}
	
	/**
	 * @param string $type
	 *
	 * @return string
	 */
	public function getType(string $type = self::TYPE_ITEMS): string
	{
		return $this->prefix($type, $this->getGroup());
	}
	
	/**
	 * Ensures that all the libraries of scripts are loaded into redis
	 * 
	 * @param bool $replace
	 *
	 * @return bool
	 */
	public function loadLibraries(bool $replace = false): bool
	{
		foreach(static::LIBRARIES as $libraryName => $libraryFile)
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
	 * 
	 * @param string $libraryName
	 * @param string $libraryFile
	 * @param bool $replace
	 * 
	 * @return bool
	 */
	public function loadLibrary
	(
		string $libraryName,
		string $libraryFile,
		bool $replace = false
	): bool
	{
		if(isset($this->_librariesLoaded[$libraryName])
			&& $this->_librariesLoaded[$libraryName] === true
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
			$list = $client->function('list', 'libraryname', $libraryName);
			
			if($list !== false
				&& isset($list[0])
				&& $list[0]['library_name'] === $libraryName
			)
			{
				$this->_librariesLoaded[$libraryName] = true;
				
				return true;
			}
		}
		
		$client->clearLastError();
		
		$library = "#!lua name=" . $libraryName . PHP_EOL . PHP_EOL
			. file_get_contents(__DIR__ . DIRECTORY_SEPARATOR . $libraryFile);
		
		$libraryLoaded = $replace
			? $client->function('load', 'replace', $library)
			: $client->function('load', $library);
		
		if($error = $client->getLastError())
		{
			throw new RedisException($error);
		}
		
		if($libraryLoaded === $libraryName)
		{
			$this->_librariesLoaded[$libraryName] = true;
			
			return true;
		}
		
		return false;
	}
	
	/**
	 * @param string $function
	 * @param array $keys
	 * @param array $args
	 * @param bool $readOnly
	 * @param bool $long
	 *
	 * @return mixed
	 */
	protected function _functionCall(
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
		
		$this->loadLibraries();
		
		if($long)
		{
			$this->_connection->toggleReadTimeout(Connection::TIMEOUT_READ_LONG);
		}
		
		$call = $readOnly
			? 'fcall_ro'
			: 'fcall'
		;
		$result = $this->_connection->slowLog([$client, $call], $function, $keys, $args);
		
		if($long)
		{
			$this->_connection->toggleReadTimeout();
		}
		
		return $result;
	}
	
	/**
	 * @param string $function
	 * @param array $keys
	 * @param array $args
	 * @param bool $readOnly
	 * @param int $batchSize
	 * @param bool $long
	 * 
	 * @return void
	 */
	protected function _batchFunctionCall(
		string $function,
		array $keys = [],
		array $args = [],
		bool $readOnly = false,
		int $batchSize = 1000,
		bool $long = false,
	): void
	{
		if($long)
		{
			// an extended timeout will be valid through all calls of the batch
			$this->_connection->toggleReadTimeout(Connection::TIMEOUT_READ_LONG);
		}
		
		$countKeys = count($keys);
		$totalBatches = (int)ceil($countKeys / $batchSize);
		
		for($batch = 0; $batch < $totalBatches; $batch++)
		{
			$keysBatch = array_slice($keys, $batch * $batchSize, $batchSize);
			$this->_functionCall($function, $keysBatch, $args, $readOnly);
		}
		
		if($long)
		{
			$this->_connection->toggleReadTimeout();
		}
	}
	
	/**
	 * @param BaseRedis $client
	 * @param string $id
	 *
	 * @return array
	 */
	protected function _getCurrentTags(BaseRedis $client, string $id): array
	{
		try
		{
			if(($itemTags = $client->hGet(
				$id,
				self::KEY_TAGS,
			)) !== false)
			{
				return explode(',', $itemTags);
			}
		}
		catch(RedisException $exception)
		{
			$this->log($exception);
		}
		
		return [];
	} 
	
	/**
	 * @param string $key
	 *
	 * @return ?array
	 */
	public function getTags(string $key): ?array
	{
		if(($client = $this->getClient()) === null)
		{
			return null;
		}
		
		try
		{
			$id = $this->prefix($key, $this->getType());
			
			return $this->_getCurrentTags($client, $id);
		}
		catch(RedisException $exception)
		{
			$this->log($exception);
		}
		
		return null;
	}
	
	/**
	 * @param string $key
	 *
	 * @return null|mixed
	 */
	public function get(string $key): mixed
	{
		if(($client = $this->getClient()) === null)
		{
			return null;
		}
		
		try
		{
			$id = $this->prefix($key, $this->getType());
			$value = $client->hGet(
				$id,
				self::KEY_DATA,
			);
			if($value === false)
			{
				return null;
			}
			
			return $this->unserialize($this->decompress($value));
		}
		catch(RedisException $exception)
		{
			$this->log($exception);
		}
		
		return null;
	}
	
	/**
	 * @param string $key
	 *
	 * @return null|bool
	 */
	public function delete(string $key): null|bool
	{
		if(($client = $this->getClient()) === null)
		{
			return null;
		}
		
		try
		{
			$id = $this->prefix($key, $this->getType());
			$tags = $this->_getCurrentTags($client, $id);
			
			$client->clearLastError();
			$client->multi($this->_multiMode);
			$client->unlink($id);
			
			foreach($tags as $tag)
			{
				$tagId = $this->prefix($tag, $this->getType(self::TYPE_TAGS));
				$client->hDel($tagId, $id);
			}
			
			$result = $client->exec();
			if($error = $client->getLastError())
			{
				$this->log($error);
			}
			
			if(is_array($result))
			{
				return $result[0] > 0; // unlink
			}
		}
		catch(RedisException $exception)
		{
			$this->log($exception);
		}
		
		return false;
	}
	
	/**
	 * @param string $key
	 * @param mixed $value
	 * @param int $ttl
	 * @param array $tags
	 * 
	 * @return bool
	 */
	public function set(
		string $key,
		mixed $value,
		int $ttl = 0,
		array $tags = [],
	): bool
	{
		if(($client = $this->getClient()) === null)
		{
			return false;
		}
		
		try
		{
			$id = $this->prefix($key, $this->getType());
			$value = $this->compress($this->serialize($value));
			
			$currentTags = $this->_getCurrentTags($client, $id);
			
			// if an item has some tags on it and a supplied array is empty,
			// then we should remove the "tags" field on the item
			if(count($currentTags) && count($tags) === 0)
			{
				$client->hDel($id, self::KEY_TAGS);
			}
			
			$client->clearLastError();
			$client->multi($this->_multiMode);
			
			$args = [$id, self::KEY_DATA, $value];
			if(count($tags))
			{
				array_push($args, 
					self::KEY_TAGS,
					implode(',', $tags)
				);
			}
			// @see https://redis.io/docs/latest/commands/hset/
			$client->hSet(...$args);
			
			// set expire if needed
			if($ttl)
			{
				$client->expire($id, $ttl);
			}
			
			$addTags = array_diff($tags, $currentTags);
			$removeTags = array_diff($currentTags, $tags);
			
			// process added tags
			foreach($addTags as $tag)
			{
				$tagId = $this->prefix($tag, $this->getType(self::TYPE_TAGS));
				
				// add the id to the list of each tag
				$client->hSet($tagId,
					$key, 
					null,
				);
				
				// expire the id in the list at the same time as id expires
				if($ttl)
				{
					$client->rawCommand('HEXPIRE', 
					$tagId,
						$ttl,
						'FIELDS',
						1,
						$key,
					);
				}
			}
			
			// process removed tags
			// remove the id from the list of each tag
			foreach($removeTags as $tag)
			{
				$tagId = $this->prefix($tag, $this->getType(self::TYPE_TAGS));
				
				$client->hDel($tagId,
					$key,
				);
			}
			
			$result = $client->exec();
			if($error = $client->getLastError())
			{
				$this->log($error);
			}
			
			if(is_array($result))
			{
				return $result[0] !== false; // hSet
			}
		}
		catch(RedisException $exception)
		{
			$this->log($exception);
		}
		
		return false;
	}
	
	/**
	 * @param array $tags
	 *
	 * @return bool
	 */
	public function invalidateTags(array $tags): bool
	{
		if(($client = $this->getClient()) === null)
		{
			return false;
		}
		
		if(count($tags) === 0)
		{
			return false;
		}
		
		$group = $this->getGroup() . self::SEPARATOR_PREFIX;
		$typeItems = self::TYPE_ITEMS . self::SEPARATOR_PREFIX;
		$typeTags = self::TYPE_TAGS . self::SEPARATOR_PREFIX;
		
		try
		{
			$ids = $this->getIdsMatchingAnyTags($tags);
			
			$client->clearLastError();
			
			// this is an option functionality, which is not required
			// at the cost of speed on invalidation; it keeps a database smaller (clean)
			// by removing ids from tags
			if($this->_cleanTags === true)
			{
				$this->_batchFunctionCall('cache_unlink_clean_tags', $ids, [
					$group,
					$typeItems,
					$typeTags,
					self::KEY_TAGS,
				], long: false);
			}
			
			if($error = $client->getLastError())
			{
				$this->log($error);
			}
			
			$client->clearLastError();
			
			foreach($tags as $tag)
			{
				$this->_functionCall('cache_unlink_by_tag', [], [
					$group,
					$tag,
					$typeItems,
					$typeTags,
				], long: false);
			}
			
			if($error = $client->getLastError())
			{
				$this->log($error);
			}
			
			return true;
		}
		catch(RedisException $exception)
		{
			$this->log($exception);
		}
		
		return false;
	}
	
	/**
	 * @param array $tags
	 *
	 * @return array
	 */
	public function getIdsMatchingAnyTags(array $tags): array
	{
		// return a unique list of ids matching any of the tags
		$ids = $this->getIdsMatchingAllTags($tags);
		$countIds = count($ids);
		
		if($countIds === 1)
		{
			return array_unique($ids[0]);
		}
		if($countIds > 1)
		{
			return array_unique(array_merge(...$ids));
		}
		
		return [];
	}
	
	/**
	 * @param array $tags
	 *
	 * @return array
	 */
	public function getIdsMatchingAllTags(array $tags): array
	{
		if(($client = $this->getClient()) === null)
		{
			return [];
		}
		
		$ids = [];
		$group = $this->getGroup() . self::SEPARATOR_PREFIX;
		$typeTags = self::TYPE_TAGS . self::SEPARATOR_PREFIX;
		
		try
		{
			$client->clearLastError();
			
			foreach($tags as $tag)
			{
				$results = $this->_functionCall('cache_get_ids_by_tag', [], [
					$group,
					$tag,
					$typeTags,
				], true);
				
				if(is_array($results))
				{
					$ids[] = $results;
				}
			}
			
			if($error = $client->getLastError())
			{
				$this->log($error);
			}
		}
		catch(RedisException $exception)
		{
			$this->log($exception);
		}
		
		return $ids;
	}
	
	/**
	 * Throws exception on purpose, this method is not meant to be used by normal users
	 * 
	 * @return bool|int
	 */
	public function clear(): bool|int
	{
		if(($client = $this->getClient()) === null)
		{
			return false;
		}
		
		$group = $this->getGroup();
		$prefix = $this->prefix('*', $group);
		$count = 0;
		
		$client->clearLastError();
		
		$result = $this->_functionCall('cache_clear', [], [
			$prefix,
		], long: true);
		
		if(is_int($result))
		{
			$count = $result;
		}
		
		if($error = $client->getLastError())
		{
			throw new RedisException($error);
		}
		
		return $count;
	}
	
	/**
	 * Returns a list of all tags
	 * 
	 * @return array
	 */
	public function getAllTags(): array
	{
		if(($client = $this->getClient()) === null)
		{
			return [];
		}
		
		$tags = [];
		$group = $this->getGroup() . self::SEPARATOR_PREFIX;
		$typeTags = self::TYPE_TAGS . self::SEPARATOR_PREFIX;
		
		try
		{
			$client->clearLastError();
			
			$results = $this->_functionCall('cache_get_tags', [], [
				$group,
				$typeTags,
			], true);
			
			if($error = $client->getLastError())
			{
				$this->log($error);
			}
			
			if(is_array($results))
			{
				$tags = array_unique($results);
			}
		}
		catch(RedisException $exception)
		{
			$this->log($exception);
		}
		
		return $tags;
	}
	
	/**
	 * Throws exception on purpose, this method is not meant to be used by normal users
	 * 
	 * @return bool|int
	 */
	public function collectGarbage(): bool|int
	{
		if(($client = $this->getClient()) === null)
		{
			return false;
		}
		
		$tags = $this->getAllTags();
		$group = $this->getGroup() . self::SEPARATOR_PREFIX;
		$typeItems = self::TYPE_ITEMS . self::SEPARATOR_PREFIX;
		$typeTags = self::TYPE_TAGS . self::SEPARATOR_PREFIX;
		$count = 0;
		
		try
		{
			$client->clearLastError();
			
			foreach($tags as $tag)
			{
				$result = $this->_functionCall('cache_clean_tag', [], [
					$group,
					$tag,
					$typeItems,
					$typeTags,
				], long: true);
				
				if(is_int($count))
				{
					$count+= $result;
				}
			}
			
			if($error = $client->getLastError())
			{
				$this->log($error);
			}
		}
		catch(RedisException $exception)
		{
			$this->log($exception);
			
			return false;
		}
		
		return $count;
	}
	
	/**
	 * Logs events (messages/errors/exceptions)
	 *
	 * @param mixed ...$event
	 *
	 * @return self
	 */
	public function log(...$event): self
	{
		$this->_connection->log(...$event);
		
		return $this;
	}
}
