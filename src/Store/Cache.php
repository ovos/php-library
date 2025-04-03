<?php
declare(strict_types=1);

namespace Ovos\Store;

use Ovos\Store;
use Ovos\ArrayObject;

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
class Cache extends Store
{
	/**
	 * Prefixes
	 */
	public const PREFIX_SERIALIZE = "\x01\xe4";
	public const PREFIX_COMPRESS = ":\x1f\x8b";
	
	/**
	 * @var ?ArrayObject
	 */
	protected ?ArrayObject $_config = null;
	
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
}
