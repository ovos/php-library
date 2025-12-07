<?php
declare(strict_types=1);

namespace Benchmarks\Store;

use Ovos\Benchmark;
use Ovos\Store\KeyValue;
use Ovos\Store\Redis as Store;
use Ovos\Test\Internal;
use Ovos\Test\Store\TraitRedis;
use Override;

/**
 * Redis
 *
 * @package Bechmarks
 * @author Marcin Gil <mg@ovos.at>
 */
class Redis extends Benchmark
{
	use TraitRedis;
	
	public const int ITEMS = 10000;
	
	public const int TAGS_PER_ITEM = 20;
	
	protected ?Store $store = null;
	
	public function __construct()
	{
		$this->group = KeyValue::GROUP_BENCHMARKS;
		$this->store = $this->getStore(Store::class);
	}
	
	protected function fill(): void
	{
		$tags = [];
		for($i = 1; $i <= self::TAGS_PER_ITEM; $i++)
		{
			$tags[] = 'tag' . $i;
		}
		
		for($i = 1; $i <= self::ITEMS; $i++)
		{
			$this->store->set('item' . $i, 'test', tags: $tags);
		}
	}
	
	/**
	 * Called by the runner before each test method
	 */
	#[Internal]
	public function prepare(): void
	{
		$this->fill();
	}
	
	public function invalidateTags(): void
	{
		$tags = ['tag1', 'tag2'];
		$this->store->invalidateTags($tags);
	}
	
	/**
	 * Called by the runner after each test method
	 */
	#[Internal]
	public function finalize(): void
	{
		$this->store->clear();
	}
	
	/**
	 * Called by the runner after all test methods have been invoked
	 */
	#[Internal]
	#[Override]
	public function deconstruct(): void
	{
		$this->connection->disconnect();
	}
}
