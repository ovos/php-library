<?php
declare(strict_types=1);

namespace Ovos\Cache\Store\KeyValue;

use Ovos\ArrayObject;
use Ovos\Cache\MemoLock\Redis as MemoLock;
use Ovos\Cache\Redis\Functions;
use Ovos\Connection\RedisCommon as Connection;
use Override;
use Closure;
use Redis as RedisClient;
use RedisCluster as RedisClusterClient;
use RedisClusterException;
use RedisException;

use function is_int;

/**
 * Redis
 *
 * @author Marcin Gil <mg@ovos.at>
 */
abstract class Redis extends Tags
{
	// Keys
	public const string KEY_DATA = 'data';
	public const string KEY_TAGS = 'tags';
	
	/**
	 * Statuses
	 *
	 * Used for rawCommand, which returns strings instead of boolean values when OPT_REPLY_LITERAL is enabled
	 * @see https://github.com/phpredis/phpredis/issues/1550
	 */
	public const string STATUS_OK = 'OK';
	
	// Types
	public const string TYPE_ITEMS = 'items';
	
	// Libraries
	/**
	 * An array of function libraries used by this class
	 */
	public const array LIBRARIES = [
		'cache' => 'Cache.lua',
	];
	
	/**
	 * Redis connection
	 */
	protected Connection $connection;
	protected Connection $queueConnection;
	
	protected Functions $functions;
	
	protected int $multiMode = RedisClient::PIPELINE;
	
	public function __construct(
		Connection $connection,
		Connection $queueConnection,
		?string $prefix = null,
		?ArrayObject $config = null,
		?string $group = null,
	)
	{
		parent::__construct($prefix, $config, $group);
		
		$this->setConnection($connection);
		$this->setQueueConnection($queueConnection);
		
		$this->functions = new Functions(
			static::LIBRARIES,
			$connection,
			$prefix,
		);
		
		$this->configure($config);
	}
	
	public function getFunctions(): Functions
	{
		return $this->functions;
	}
	
	public function configure(
		?ArrayObject $config = null,
	): static
	{
		if($config === null)
		{
			return $this;
		}
		
		if($storeOptions = $config->offsetGet('store_options'))
		{
			$this->setStoreOptions($storeOptions);
		}
		
		return $this;
	}
	
	public function setConnection(
		Connection $connection,
	): static
	{
		$this->connection = $connection;
		
		return $this;
	}
	
	public function getConnection(): Connection
	{
		return $this->connection;
	}
	
	public function setQueueConnection(
		Connection $connection,
	): static
	{
		$this->queueConnection = $connection;
		
		return $this;
	}
	
	public function getQueueConnection(): Connection
	{
		return $this->queueConnection;
	}
	
	public function getClient(): RedisClient|RedisClusterClient|null
	{
		return $this->connection->getClient();
	}
	
	public function setStoreOptions(
		ArrayObject $options,
	): static
	{
		return $this;
	}
	
	public function getMemoLock(): MemoLock
	{
		// initialize the MemoLock on demand
		// (when there is no cache hit)
		if($this->memoLock === null)
		{
			// pub/sub requires a separate connection,
			// otherwise we will be getting "subscribe" & "unsubscribe"
			// messages on hGet
			$this->memoLock = new MemoLock(
				$this->connection,
				$this->queueConnection,
				$this->prefixer->getPrefix(),
				$this->config,
				$this,
			);
		}
		
		return $this->memoLock;
	}
	
	public function getType(
		string $type = self::TYPE_ITEMS,
	): string
	{
		return $this->prefixer
			->prefix($type, $this->getGroup());
	}
	
	protected function fetch(
		string $id,
	): mixed
	{
		if(($client = $this->getClient()) === null)
		{
			return null;
		}
		
		try
		{
			$value = $client->hGet(
				$id,
				static::KEY_DATA,
			);
			
			if($value !== false)
			{
				$value = $this->compressor
					->decompress($value);
				return $this->serializer
					->unserialize($value);
			}
		}
		// RedisClusterException does not extend RedisException, catch both
		catch(RedisException|RedisClusterException $exception)
		{
			$this->log($exception);
		}
		
		return null;
	}
	
	#[Override]
	public function get(
		string $key,
		?Closure $resolver = null,
		int $ttl = 0,
		array $tags = [],
		?bool $queue = null, // override of the config switch
		?int $queueLockTtlMs = null, // override of the config value
	): mixed
	{
		if($this->getClient() === null)
		{
			// no connection (fast path)
			return $this->setFromResolver($key, $resolver, $ttl, $tags);
		}
		
		$id = $this->prefixer
			->prefix($key, $this->getType());
		
		// initial hit check (fast path)
		if(($data = $this->fetch($id)) !== null)
		{
			return $data;
		}
		
		return $this->getMemoLock()
			->lockAndQueue(
				$id,
				fn() => $this->fetch($id),
				fn() => $this->setFromResolver($key, $resolver, $ttl, $tags),
				$queue,
				$queueLockTtlMs,
			);
	}
	
	public function lockAndQueue(
		string $key,
		?Closure $resolver = null,
		?int $queueLockTtlMs = null, // override of the config value
	): mixed
	{
		if($this->getClient() === null)
		{
			// no connection (fast path)
			return $this->invoker
				->invoke($resolver);
		}
		
		$id = $this->prefixer
			->prefix($key, $this->getType());
		
		return $this->getMemoLock()
			->lockAndQueue(
				$id,
				null,
				$resolver,
				true,
				$queueLockTtlMs,
			);
	}
	
	public function releaseActiveLock(
		string $key,
	): bool
	{
		$id = $this->prefixer
			->prefix($key, $this->getType());
		
		return $this->getMemoLock()
			->releaseActiveLock($id);
	}
	
	public function renewLock(
		string $key,
	): bool
	{
		$id = $this->prefixer
			->prefix($key, $this->getType());
		
		return $this->getMemoLock()
			->renewLock($id);
	}
	
	/**
	 * Throws exception on purpose, this method is not meant to be used by normal users
	 */
	public function clear(): bool|int
	{
		if(($client = $this->getClient()) === null)
		{
			return false;
		}
		
		$group = $this->getGroup();
		$prefix = $this->prefixer
			->prefix('*', $group);
		$count = 0;
		
		$client->clearLastError();
		
		$result = $this->functions->call('cache_clear', [], [
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
	 * Reclaims whatever a store accumulates that does not expire on its own.
	 * The default is a no-op: items carry a TTL and there is no side index to
	 * sweep. The tag-hash store overrides this to prune dangling tag -> id
	 * references; the versioned stores keep the default (their rules stream
	 * self-trims and stale items expire by TTL). Defined here so the cache
	 * maintenance cron can call collectGarbage() on any persistent store.
	 */
	public function collectGarbage(): bool|int
	{
		return true;
	}
	
	/**
	 * Logs events (messages/errors/exceptions)
	 */
	public function log(
		...$event,
	): static
	{
		$this->connection->log(...$event);
		
		return $this;
	}
}
