<?php
declare(strict_types=1);

namespace Benchmarks\Store;

use Ovos\ArrayObject;
use Ovos\Benchmark;
use Ovos\Connection\Redis as Connection;
use Ovos\Connections;
use Ovos\Container\ArrayObject as InjectArrayObject;
use Ovos\Container\Inject;
use Ovos\Store\KeyValue;
use Ovos\Store\Redisearch as RedisStore;
use Ovos\Test\Internal;
use RedisException;
use ReflectionClass;

use function sprintf;

/**
 * Redisearch
 *
 * @package Bechmarks
 * @author Marcin Gil <mg@ovos.at>
 */
class Redisearch extends Benchmark
{
	/**
	 * @var int
	 */
	public const int ITEMS = 10000;
	
	/**
	 * @var int
	 */
	public const int TAGS_PER_ITEM = 20;
	
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
	 * @var ?RedisStore
	 */
	protected ?RedisStore $_store = null;
	
	public function __construct()
	{
		$storeClass = $this->_config->persistent->store;
		$currentClass = (new ReflectionClass($this))->getShortName();
		if($storeClass !== $currentClass)
		{
			$this->setIsDisabled(true,
				sprintf('"store" is set to "%s".', $storeClass)
			);
		}
		
		$this->_connection = $this->_container
			->getClass(Connections::class)
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
		$this->_store = new RedisStore
		(
			$this->_connection,
			$this->_config->persistent,
			$this->_config->prefix,
			KeyValue::GROUP_BENCHMARKS,
		);
	}
	
	protected function _fill(): void
	{
		$tags = [];
		for($i = 1; $i <= self::TAGS_PER_ITEM; $i++)
		{
			$tags[] = 'tag' . $i;
		}
		
		for($i = 1; $i <= self::ITEMS; $i++)
		{
			$this->_store->set('item' . $i, 'test', tags: $tags);
		}
	}
	
	/**
	 * Called by the runner before each test method
	 */
	#[Internal]
	public function prepare(): void
	{
		$this->_initStore();
		$this->_store->indexRebuild();
		$this->_fill();
	}
	
	public function invalidateTags(): void
	{
		$tags = ['tag1', 'tag2'];
		$this->_store->invalidateTags($tags);
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
		$this->_connection->disconnect();
	}
}
