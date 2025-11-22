<?php
declare(strict_types=1);

namespace Benchmarks\Store;

use Ovos\Benchmark;
use Ovos\Store\KeyValue;
use Ovos\Store\Redisearch as Store;
use Ovos\Test\Internal;
use Ovos\Test\Store\TraitRedis;
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
	use TraitRedis;
	
	/**
	 * @var int
	 */
	public const int ITEMS = 10000;
	
	/**
	 * @var int
	 */
	public const int TAGS_PER_ITEM = 20;
	
	/**
	 * @var ?Store
	 */
	protected ?Store $_store = null;
	
	public function __construct()
	{
		$storeClass = $this->_cacheConfig->persistent->store;
		$currentClass = (new ReflectionClass($this))->getShortName();
		if($storeClass !== $currentClass)
		{
			$this->setIsDisabled(true,
				sprintf('"store" is set to "%s".', $storeClass)
			);
		}
		
		$this->_group = KeyValue::GROUP_BENCHMARKS;
		$this->_store = $this->_getStore(Store::class);
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
}
