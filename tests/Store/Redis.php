<?php
declare(strict_types=1);

namespace Tests\Store;

use Ovos\ArrayObject;
use Ovos\Store\Redis as RedisStore;
use Ovos\Test;

use function Ovos\config;

/**
 * Redis
 *
 * @package Tests
 * @author Marcin Gil <mg@ovos.at>
 */
class Redis extends Test
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
		
		$array = [
			'stored' => true,
		];
		
		$this->_store->set('array', $array);
		$array = $this->_store->get('array');
		
		return $array['stored'] === true;
	}
	
	public function clear(): bool
	{
		$this->_connect();
		
		$array = [
			'stored' => true,
		];
		
		$this->_store->set('array', $array);
		$this->_store->clear();
		$result = $this->_store->get('array');
		
		return $result === null;
	}
}
