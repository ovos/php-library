<?php
declare(strict_types=1);

namespace Ovos\Service\Cache;

use Ovos\ArrayObject;
use Ovos\Cache\MemoLock\Redis as MemoLock;
use Ovos\Cache\Store\KeyValue\Redis as Store;
use Ovos\Connections;
use Ovos\Connection\RedisCommon as Connection;
use Ovos\Container;
use Ovos\Container\Inject;
use Ovos\Exception\MissingException\MissingConfigException;

/**
 * Persistent
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
	
	public function getStore(
	): Store
	{
		if($this->store === null)
		{
			/** @var Store $storeClass */
			$storeClass = 'Ovos\Cache\Store\\'
				. ($this->config->persistent->store ?? 'Redis');
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
