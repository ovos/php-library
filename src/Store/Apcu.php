<?php
declare(strict_types=1);

namespace Ovos\Store;

use Ovos\ArrayObject;
use Ovos\Arrays;
use Ovos\Exception;
use APCUIterator;

/**
 * Apcu
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
class Apcu extends Cache
{
	/**
	 * @var ?string 
	 */
	protected ?string $_prefix = null;

	/**
	 * @param ?string $prefix
	 */
	public function __construct(?string $prefix = null)
	{
		parent::__construct();
		
		// this store does not rely on config availability on purpose
	
		$this->setPrefix($prefix);
	}
	
	/**
	 * @param ArrayObject $config
	 *
	 * @return self
	 * @throws Exception
	 */
	public static function fromConfig(ArrayObject $config): self
	{
		if($config->offsetExists('prefix') === false)
		{
			throw new Exception('"cache: prefix" is a required config value.');
		}
	
		$instance = new self;
		$instance->setConfig($config->perishable);
		
		return $instance;
	}
	
	/**
	 * @param ?string $prefix
	 *
	 * @return self
	 */
	public function setPrefix(?string $prefix = null): self
	{
		$this->_prefix = $prefix;
		
		return $this;
	}
	
	/**
	 * @param string $key
	 * @param mixed $value
	 * @param int $ttl
	 *
	 * @return bool
	 */
	public function set(string $key, mixed $value, int $ttl = 0): bool
	{
		$value = $this->compress($this->serialize($value));
	
		return apcu_store($this->_prefix . $key, $value, $ttl);
	}
	
	/**
	 * @param string $key
	 *
	 * @return null|mixed
	 */
	public function get(string $key): mixed
	{
		$value = apcu_fetch($this->_prefix . $key);
		if($value === false)
		{
			return null;
		}
		
		return $this->unserialize($this->decompress($value));
	}
	
	/**
	 * @param string|APCUIterator $key
	 *
	 * @return bool
	 */
	public function delete(string|APCUIterator $key): bool
	{
		if(is_string($key))
		{
			$key = $this->_prefix . $key;
		}
	
		return apcu_delete($key);
	}
	
	/**
	 * @param bool $limited
	 *
	 * @return bool|array
	 */
	public function info(bool $limited = false): bool|array
	{
		return apcu_cache_info($limited);
	}
	
	/**
	 * @return bool - always true
	 */
	public function clear(): bool
	{
		return apcu_clear_cache();
	}
}
