<?php
declare(strict_types=1);

namespace Ovos\Cache\Adapter\Common;

use Cache\Adapter\Common\CacheItem as BaseCacheItem;
use Ovos\ArrayObject;
use Ovos\Service\Cache;
use ReflectionObject;
use Closure;
use Redis;

class CacheItem extends BaseCacheItem
{
	/**
	 * Compress prefix
	 */
	public const COMPRESS_PREFIX = ":\x1f\x8b";

	/**
	 * @var ArrayObject
	 */
	protected $_config;

	/**
	 * @var bool
	 */
	protected $_raw = false;

	/**
	 * @param ArrayObject $config
	 * @param string $key
	 * @param Closure|bool $callable or boolean hasValue
	 */
	public function __construct(ArrayObject $config, $key, $callable = null)
	{
		parent::__construct($key, $callable);
		$this->setConfig($config);
	}
	
	/**
	 * @param ArrayObject $config
	 * 
	 * @return $this
	 */
	public function setConfig(ArrayObject $config): self
	{
		$this->_config = $config;
		
		return $this;
	}

	/**
	 * @return ArrayObject
	 */
	public function getConfig(): ArrayObject
	{
		return $this->_config;
	}

	/**
	 * @param bool $raw
	 * 
	 * @return $this
	 */
	public function setRaw(bool $raw): self
	{
		$this->_raw = $raw;
		
		return $this;
	}

	/**
	 * @return bool
	 */
	public function isRaw(): bool
	{
		return $this->_raw;
	}
	
	/**
	 * {@inheritdoc}
	 */
	public function get()
	{
		return $this->_raw ? parent::get()
			: $this->decompress(parent::get());
	}

	/**
	 * {@inheritdoc}
	 */
	public function set($value)
	{
		return $this->_raw ? parent::set($value)
			: parent::set($this->compress($value));
	}
	
	/**
	 * @param null|mixed $value
	 * 
	 * @return null|string
	 */
	public function compress($value): ?string
	{
		if($value === null)
		{
			return null;
		}
		
		if($this->_config->compression->enabled !== true)
		{
			return $value;
		}
		
		$value = is_object($value) ?
			serialize($value)
			: (string)$value;
		
		if($this->_config->compression->threshold !== null
			&& strlen($value) < $this->_config->compression->threshold)
		{
			return $value;
		}
		
		// use zstd if available, gzip otherwise
		if(function_exists('zstd_compress'))
		{
			$value = 'zs' . self::COMPRESS_PREFIX . zstd_compress($value, 4);
		}
		else
		{
			$value = 'gz' . self::COMPRESS_PREFIX . gzcompress($value, 3);
		}
	
		return $value;
	}	

	/**
	 * @param null|string $value
	 * 
	 * @return null|mixed
	 */
	public function decompress(?string $value)
	{
		if($value === null)
		{
			return null;
		}
		
		if($this->_config->compression->enabled !== true)
		{
			return $value;
		}
		
		$prefix = substr($value, 2, 3);
		if($prefix !== self::COMPRESS_PREFIX)
		{
			return $value; // not compressed
		}
		
		$method = substr($value, 0, 2);
		$compressed = substr($value, 5);
		switch($method)
		{
			case 'zs':
				if(function_exists('zstd_uncompress'))
				{
					$value = zstd_uncompress($compressed);
				}
				
				break;
			default:
				$value = gzuncompress($compressed);
		}
	
		return unserialize($value, ['allowed_classes' => true]);
	}
}

