<?php
declare(strict_types=1);

namespace Tests\Store;

use Ovos\ArrayObject;
use Ovos\Connection\Redis as Connection;
use Ovos\Connections;
use Ovos\Container\ArrayObject as InjectArrayObject;
use Ovos\Container\Inject;
use Ovos\Store\KeyValue;
use Ovos\Store\Redisearch as Store;
use Ovos\Test;
use Ovos\Test\Internal;
use RedisException;

use function Ovos\config;
use function sprintf;

/**
 * Redisearch
 *
 * @package Tests
 * @author Marcin Gil <mg@ovos.at>
 */
class Redisearch extends Test
{
	/**
	 * @var string
	 */
	public const string KEY_ITEM = 'item';
	
	/**
	 * @var ArrayObject
	 */
	#[Inject('config')]
	#[InjectArrayObject('cache')]
	protected ArrayObject $_config;
	
	/**
	 * @var ?Connection
	 */
	protected ?Connection $_connection = null;
	
	/**
	 * @var ?Store
	 */
	protected ?Store $_store = null;
	
	public function __construct()
	{
		$connections = $this->_container
			->getClass(Connections::class);
		$connections->getConfig()->redis->database = 0;
	
		$this->_connection = $connections
			->get($this->_config->persistent->connection);
		
		if($this->_connection->connect() === false)
		{
			throw new RedisException
			(
				sprintf('Could not connect to redis server "%s" on port "%s".',
					$this->_store->getConfig()->host,
					$this->_store->getConfig()->port,
				)
			);
		}
	}
	
	protected function _initStore(): void
	{
		$this->_store = new Store
		(
			$this->_connection,
			$this->_config->persistent,
			$this->_config->prefix,
			KeyValue::GROUP_TESTS,
		);
	}
	
	/**
	 * Called by the runner before each test method
	 */
	#[Internal]
	public function prepare(): void
	{
		$this->_initStore();
	}
	
	public function store(): bool
	{
		return true;
	}
	
	public function delete(): bool
	{
		$this->_store->set(self::KEY_ITEM, 'test');
		$this->_store->delete(self::KEY_ITEM);
		
		$exists = $this->_store->get(self::KEY_ITEM, queue: false);
		
		return $exists === null;
	}
	
	public function storeArray(): bool
	{
		$this->_store->indexRebuild();
		
		$array = [
			'stored' => true
		];
		
		$this->_store->set(self::KEY_ITEM, $array);
		$array = $this->_store->get(self::KEY_ITEM, queue: false);
		
		try
		{
			return $array['stored'] === true;
		}
		finally
		{
			$this->_store->delete(self::KEY_ITEM);
		}
	}
	
	public function invalidateTags(): bool
	{
		$this->_store->indexRebuild();
		
		$tags = ['tag1', 'tag2'];
		
		$this->_store->set(self::KEY_ITEM, 'test', tags: $tags);
		$this->_store->invalidateTags([$tags[0]]);
		
		$result = $this->_store->get(self::KEY_ITEM, queue: false);
		
		try
		{
			return $result === null;
		}
		finally
		{
			$this->_store->delete(self::KEY_ITEM);
		}
	}
	
	public function clear(): bool
	{
		$this->_store->indexRebuild();
		
		$array = [
			'stored' => true
		];
		
		$this->_store->set(self::KEY_ITEM, $array);
		$this->_store->clear();
		$result = $this->_store->get(self::KEY_ITEM, queue: false);
		
		return $result === null;
	}
	
	/**
	 * Called by the runner after each test method
	 */
	#[Internal]
	public function finalize(): void
	{
		$this->_store->clear();
	}	
	
	/**
	 * Called by the runner after all test methods have been invoked
	 */
	#[Internal]
	public function deconstruct(): void
	{
		$this->_store->indexDrop($this->_store->getType());
		$this->_connection->disconnect();
	}
}
