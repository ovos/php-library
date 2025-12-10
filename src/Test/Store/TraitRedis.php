<?php
declare(strict_types=1);

namespace Ovos\Test\Store;

use Ovos\ArrayObject;
use Ovos\Connection\Redis as Connection;
use Ovos\Connections;
use Ovos\Container\ArrayObject as InjectArrayObject;
use Ovos\Container\Inject;
use Ovos\Store\KeyValue;

use function sprintf;

/**
 * TraitRedis
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
trait TraitRedis
{
	#[Inject('config')]
	#[InjectArrayObject('cache')]
	protected ArrayObject $cacheConfig;
	
	protected ?Connection $connection = null;
	
	protected string $group = KeyValue::GROUP_TESTS;
	
	protected function getConnections(): Connections
	{
		return $this->container
			->getClass(Connections::class);
	}
	
	protected function getConnection(): Connection
	{
		/** @var Connection $connection */
		$connection = $this->getConnections()
			->get($this->cacheConfig->persistent->connection);
		
		return $connection;
	}
	
	protected function connect(): Connection
	{
		if($this->connection === null)
		{
			$connection = $this->getConnection();
			if($connection->connect() === false)
			{
				throw new RedisException
				(
					sprintf('Could not connect to redis server "%s" on port "%s".',
						$connection->getConfig()->host,
						$connection->getConfig()->port,
					)
				);
			}
			
			$this->connection = $connection;
		}
		
		return $this->connection;
	}
	
	protected function getStore(
		string $storeClass,
	): object
	{
		return new $storeClass
		(
			$this->connect(),
			$this->cacheConfig->persistent,
			$this->cacheConfig->prefix,
			$this->group,
		);
	}
}

