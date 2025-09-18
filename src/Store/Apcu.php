<?php
declare(strict_types=1);

namespace Ovos\Store;

use Ovos\ArrayObject;
use Ovos\Exception;
use APCUIterator;

use function is_string;
use function apcu_store;
use function apcu_fetch;
use function apcu_delete;
use function apcu_cache_info;
use function apcu_clear_cache;

/**
 * Apcu
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
class Apcu extends Cache
{
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
		
		$instance = new self($config->prefix);
		$instance->setConfig($config->perishable);
		
		return $instance;
	}
	
	/**
	 * Returns "id" to be used as cache id form a path string
	 * For example: /home/user/my-file.txt -> user-my-file-txt
	 * or C:\Users\User\Desktop\my-file.txt -> user-my-file-txt
	 *
	 * @param string $string
	 *
	 * @return string
	 */
	public static function pathToId(string $string): string
	{
		$string = mb_strtolower($string);
		
		if(substr($string, 1, 2) === ':\\') // windows drive
		{
			$string = substr($string, 3);
		}
		else
		{
			$string = ltrim($string, '~'); // linux home
			$string = ltrim($string, DIRECTORY_SEPARATOR);
		}
		
		$string = str_replace([
			'/',
			'\\',
			'.', // dot
		], '-', $string);
		
		$string = trim($string, '-');
		
		return $string;
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
		
		return apcu_store($this->prefix($key), $value, $ttl);
	}
	
	/**
	 * @param string $key
	 *
	 * @return null|mixed
	 */
	public function get(string $key): mixed
	{
		$value = apcu_fetch($this->prefix($key));
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
			$key = $this->prefix($key);
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
