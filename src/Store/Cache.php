<?php
declare(strict_types=1);

namespace Ovos\Store;

use Ovos\Store;
use Ovos\ArrayObject;
use Closure;

use function is_array;
use function is_object;
use function serialize;
use function substr;
use function unserialize;
use function function_exists;
use function strlen;
use function gzcompress;
use function gzuncompress;

/**
 * Cache
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
abstract class Cache extends Store
{
	/**
	 * Prefixes
	 */
	public const string PREFIX_SERIALIZE = "\x01\xe4";
	public const string PREFIX_COMPRESS = ":\x1f\x8b";
	/**#@-*/
	
	/**#@+
	 * Separators
	 */
	public const string SEPARATOR_PREFIX = ':';
	/**#@-*/
	
	/**
	 * @var ?string 
	 */
	protected ?string $_prefix = null;
	
	/**#@+
	 * Type constants
	 */
	public const string GROUP_DEFAULT = 'core';
	public const string GROUP_TESTS = 'tests';
	public const string GROUP_BENCHMARKS = 'benchmarks';
	/**#@-*/
	
	/**
	 * @var ?string
	 */
	protected ?string $_group = self::GROUP_DEFAULT;
	
	/**
	 * @var ?ArrayObject
	 */
	protected ?ArrayObject $_config = null;
	
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
	 * @param ?string $prefix
	 * @param string $separator
	 *
	 * @return string
	 */
	public function prefix(string $key,
		?string $prefix = null,
		string $separator = self::SEPARATOR_PREFIX
	): string
	{
		$prefix = $prefix ?? $this->_prefix;
		
		return $prefix !== null
			? $prefix . $separator . $key
			: $key;
	}
	
	/**
	 * @param ?string $group
	 *
	 * @return self
	 */
	public function setGroup(?string $group): self
	{
		$this->_group = $group;
		
		return $this;
	}
	
	/**
	 * @return string
	 */
	public function getGroup(): string
	{
		return $this->prefix($this->_group);
	}
	
	/**
	 * @param ?ArrayObject $config
	 * 
	 * @return self
	 */
	public function setConfig(?ArrayObject $config): self
	{
		$this->_config = $config;
		
		return $this;
	}
	
	/**
	 * @return ?ArrayObject
	 */
	public function getConfig(): ?ArrayObject
	{
		return $this->_config;
	}
	
	/**
	 * @param null|mixed $value
	 * 
	 * @return ?string
	 */
	public function serialize(mixed $value): ?string
	{
		if($value === null)
		{
			return null;
		}
		
		if(is_array($value) || is_object($value))
		{
			$value = self::PREFIX_SERIALIZE . serialize($value);
		}
		
		return (string)$value;
	}
	
	/**
	 * @param ?string $value
	 * 
	 * @return null|mixed
	 */
	public function unserialize(?string $value): mixed
	{
		if($value === null)
		{
			return null;
		}
		
		$prefix = substr($value, 0, 2);
		if($prefix !== self::PREFIX_SERIALIZE)
		{
			return $value; // not serialized
		}
		$value = substr($value, 2);
		
		return unserialize($value, ['allowed_classes' => true]);
	}
	
	/**
	 * @param null|mixed $value
	 * 
	 * @return ?string
	 */
	public function compress(mixed $value): ?string
	{
		if($value === null)
		{
			return null;
		}
		
		if($this->_config === null
			|| $this->_config->compression->enabled !== true)
		{
			return $value;
		}
		
		if($this->_config->compression->threshold !== null
			&& strlen($value) < $this->_config->compression->threshold)
		{
			return $value;
		}
		
		// use zstd if available, gzip otherwise
		if(function_exists('zstd_compress'))
		{
			$value = 'zs' . self::PREFIX_COMPRESS . zstd_compress($value, 4);
		}
		else
		{
			$value = 'gz' . self::PREFIX_COMPRESS . gzcompress($value, 3);
		}
		
		return $value;
	}
	
	/**
	 * @param ?string $value
	 * 
	 * @return null|mixed
	 */
	public function decompress(?string $value): mixed
	{
		if($value === null)
		{
			return null;
		}
		
		if($this->_config === null
			|| $this->_config->compression->enabled !== true)
		{
			return $value;
		}
		
		$prefix = substr($value, 2, 3);
		if($prefix === self::PREFIX_COMPRESS) // compressed
		{
			$method = substr($value, 0, 2);
			$compressed = substr($value, 5);
			switch($method)
			{
				case 'zs':
					if(function_exists('zstd_uncompress') === false)
					{
						return $value;
					}
					
					$value = zstd_uncompress($compressed);
					
					break;
				default:
					$value = gzuncompress($compressed);
			}
		}
		
		return $value;
	}
	
	/**
	 * @param string $key
	 * @param ?Closure $setCallback
	 * @param int $ttl
	 *
	 * @return null|mixed
	 */
	abstract public function get(
		string $key,
		?Closure $setCallback = null,
		int $ttl = 0,
	): mixed;
	
	/**
	 * @param string $key
	 * @param ?Closure $setCallback
	 * @param int $ttl
	 *
	 * @return mixed
	 */
	public function setFromCallback(
		string $key,
		?Closure $setCallback,
		int $ttl = 0,
	): mixed
	{
		if($setCallback === null)
		{
			return null;
		}
		
		$value = $setCallback($this);
		$this->set($key, $value, $ttl);
		
		return $value;
	}
	
	/**
	 * @param ?Closure $setCallback
	 *
	 * @return null|mixed
	 */
	public function callSetCallback(?Closure $setCallback = null): mixed
	{
		if($setCallback === null)
		{
			return null;
		}
		
		return $setCallback($this);
	}
	
	/**
	 * @param string $key
	 * @param mixed $value
	 * @param int $ttl
	 *
	 * @return bool
	 */
	abstract public function set(
		string $key,
		mixed $value,
		int $ttl = 0,
	): bool;
}
