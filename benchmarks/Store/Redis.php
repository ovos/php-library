<?php
declare(strict_types=1);

namespace Benchmarks\Store;

use Ovos\ArrayObject;
use Ovos\Benchmark;
use Ovos\Redis\Connection;
use Ovos\Store\Cache;
use Ovos\Store\Redis as RedisStore;
use Ovos\Test\Internal;

use function Ovos\config;
use function sprintf;

/**
 * Redis
 *
 * @package Bechmarks
 * @author Marcin Gil <mg@ovos.at>
 */
class Redis extends Benchmark
{
	/**
	 * @var ArrayObject
	 */
	protected ArrayObject $_config;
	
	/**
	 * @var ?RedisStore
	 */
	protected ?RedisStore $_store = null;
	
	/**
	 * @var int
	 */
	protected int $_items = 10000;
	
	/**
	 * @var int
	 */
	protected int $_tagsPerItem = 20;
	
	public function __construct()
	{
		$this->_config = config()->cache;
	}
	
	protected function _initStore(): void
	{
		$connection = new Connection($this->_config->persistent);
		if($connection->connect() === false)
		{
			throw new RedisException
			(
				sprintf('Could not connect to redis server "%s" on port "%s".',
					$this->_store->getConfig()->host,
					$this->_store->getConfig()->port,
				)
			);
		}
		
		$this->_store = new RedisStore
		(
			$this->_config->prefix,
			$connection,
			$this->_config->persistent,
			Cache::GROUP_BENCHMARKS
		);
	}
	
	protected function _fill(): void
	{
		$tags = [];
		for($i = 1; $i <= $this->_tagsPerItem; $i++)
		{
			$tags[] = 'tag' . $i;
		}
		
		for($i = 1; $i <= $this->_items; $i++)
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
}
