<?php
declare(strict_types=1);

namespace Ovos\Service\Cache;

use Ovos\ArrayObject;
use Ovos\Cache\MemoLock\Redis as MemoLock;
use Ovos\Cache\Store\KeyValue as Store;
use Ovos\Connections;
use Ovos\Connection\RedisCommon as Connection;
use Ovos\Container;
use Ovos\Container\Inject;
use Ovos\Exception\InvalidException\InvalidConfigException;
use Ovos\Exception\MissingException\MissingConfigException;
use ReflectionClass;

use function is_a;
use function ltrim;
use function str_contains;

/**
 * Persistent
 *
 * The tier's store is any KeyValue store: one of the library's, named bare
 * (`store: RedisVersioned`), or a project's own, named by its class
 * (`store: \App\Cache\Store\Mine`). See storeClass().
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Persistent
{
	#[Inject]
	protected Container $container;
	
	protected ArrayObject $config;
	
	protected ?Connection $connection = null;
	protected ?Connection $queueConnection = null;
	
	protected ?Store $store = null;
	
	protected ?MemoLock $queue = null;
	
	public function __construct(
		ArrayObject $config,
	)
	{
		$this->config = $config;
	}
	
	public function getConfig(): ArrayObject
	{
		return $this->config;
	}
	
	public function getConnectionName(): ?string
	{
		if($this->config->persistent->connection !== null)
		{
			return $this->config->persistent->connection;
		}
		
		throw new MissingConfigException(
			'"connection" config section is missing.');
	}
	
	public function getQueueConnectionName(): ?string
	{
		if($this->config->persistent->queue?->connection !== null)
		{
			return $this->config->persistent->queue->connection;
		}
		
		return $this->getConnectionName();
	}
	
	public function getConnection(): ?Connection
	{
		if($this->connection === null)
		{
			$this->connection = $this->container
				->getClass(Connections::class)
				->get($this->getConnectionName());
		}
		
		return $this->connection;
	}
	
	public function getQueueConnection(): ?Connection
	{
		if($this->queueConnection === null)
		{
			$this->queueConnection = $this->container
				->getClass(Connections::class)
				// using modifier to distinguish this connection from the default one
				->get($this->getQueueConnectionName(), 'queue');
		}
		
		return $this->queueConnection;
	}
	
	/**
	 * The configured store, built once. A store named by its class is built
	 * with the arguments every Redis store takes - the connection, the queue
	 * connection, the prefix and the tier's configuration - whether it uses
	 * the connections or not.
	 *
	 * @throws InvalidConfigException the configuration names no store (see storeClass())
	 */
	public function getStore(
	): Store
	{
		if($this->store === null)
		{
			$storeClass = static::storeClass((string)($this->config->persistent->store ?? 'Redis'));
			$store = new $storeClass(
				$this->getConnection(),
				$this->getQueueConnection(),
				$this->config->prefix,
				$this->config->persistent,
			);
			
			$this->store = $store;
		}
		
		return $this->store;
	}
	
	/**
	 * The class `cache.persistent.store` stands for. A bare name is one of the
	 * library's stores, as it always was: "Redis" is Ovos\Cache\Store\Redis,
	 * never phpredis' own \Redis, which class_exists() would find as well. A
	 * name with a namespace separator is a class, as it is written, with or
	 * without the leading backslash.
	 *
	 * @return class-string<Store>
	 * @throws InvalidConfigException a class that does not exist, is abstract or is no KeyValue store
	 */
	public static function storeClass(
		string $name,
	): string
	{
		$class = str_contains($name, '\\')
			? ltrim($name, '\\')
			: 'Ovos\Cache\Store\\' . $name;
		
		if(is_a($class, Store::class, true) === false
			|| (new ReflectionClass($class))->isAbstract())
		{
			throw new InvalidConfigException(
				'cache.persistent.store "%s" names %s, which is not a store: a store is a class that extends %s and is not abstract.',
				$name,
				$class,
				Store::class,
			);
		}
		
		return $class;
	}
	
	public function getQueue(
	): MemoLock
	{
		if($this->queue === null)
		{
			$this->queue = new MemoLock(
				$this->getConnection(),
				$this->getQueueConnection(),
				$this->config->prefix,
				$this->config->persistent,
			);
		}
		
		return $this->queue;
	}
}
