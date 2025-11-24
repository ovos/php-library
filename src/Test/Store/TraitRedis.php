<?php
declare(strict_types=1);

namespace Ovos\Test\Store;

use Ovos\ArrayObject;
use Ovos\Connection\Redis as Connection;
use Ovos\Connections;
use Ovos\Container\ArrayObject as InjectArrayObject;
use Ovos\Container\Inject;
use Ovos\Store\KeyValue;
use Ovos\Test\Internal;
use Override;

use function sprintf;

/**
 * TraitRedis
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
trait TraitRedis
{
	/**
	 * @var ArrayObject
	 */
	#[Inject('config')]
	#[InjectArrayObject('cache')]
	protected ArrayObject $_cacheConfig;
	
	/**
	 * @var ?Connection
	 */
	protected ?Connection $_connection = null;
	
	/**
	 * @var string
	 */
	protected string $_group = KeyValue::GROUP_TESTS;
	
	protected function _getConnections(): Connections
	{
		return $this->_container
			->getClass(Connections::class);
	}
	
	protected function _getConnection(): Connection
	{
		/** @var Connection $connection */
		$connection = $this->_getConnections()
			->get($this->_cacheConfig->persistent->connection);
		
		return $connection;
	}
	
	protected function _connect(): Connection
	{
		if($this->_connection === null)
		{
			$connection = $this->_getConnection();
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
			
			$this->_connection = $connection;
		}
		
		return $this->_connection;
	}
	
	protected function _getStore($storeClass): object
	{
		return new $storeClass
		(
			$this->_connect(),
			$this->_cacheConfig->persistent,
			$this->_cacheConfig->prefix,
			$this->_group,
		);
	}
}

