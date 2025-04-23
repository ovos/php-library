<?php
declare(strict_types=1);

namespace Tests\Store;

use Ovos\ArrayObject;
use Ovos\Store\Redisearch as RedisStore;
use Ovos\Test;

use function Ovos\config;

/**
 * Redisearch
 *
 * @package Tests
 * @author Marcin Gil <mg@ovos.at>
 */
class Redisearch extends Test
{
	/**
	 * @var ArrayObject
	 */
	protected ArrayObject $_config;
	
	/**
	 * @var ?RedisStore
	 */
	protected ?RedisStore $_store = null;
	
	public function __construct()
	{
		$this->_config = config()->cache;
		
		$storeClass = $this->_config->persistent->store;
		if($storeClass !== __CLASS__)
		{
			$this->reason = sprintf('"store" is set to "%s".', $storeClass);
			$this->setIsDisabled(true);
		}
	}
	
	protected function _connect(): bool
	{
		$this->_store = new RedisStore($this->_config);
		
		return $this->_store->connect();
	}
		
	public function connect(): bool
	{
		return $this->_connect();
	}
	
	public function storeArray(): bool
	{
		$this->_connect();
		$this->_store->indexRebuild();
		
		$array = [
			'stored' => true
		];
		
		$this->_store->set('array', $array);
		$array = $this->_store->get('array');
		
		return $array['stored'] === true;
	}
	
	public function invalidateTags(): bool
	{
		$this->_connect();
		$this->_store->indexRebuild();
		
		$this->_store->set('array', 'test', tags: ['tag1', 'tag2']);
		$this->_store->invalidateTags(['tag1']);
		
		$result = $this->_store->get('array');
		
		return $result === null;
	}
	
	public function clear(): bool
	{
		$this->_connect();
		$this->_store->indexRebuild();
		
		$array = [
			'stored' => true
		];
		
		$this->_store->set('array', $array);
		$this->_store->clear();
		$result = $this->_store->get('array');
		
		return $result === null;
	}
}
