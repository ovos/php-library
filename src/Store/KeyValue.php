<?php
declare(strict_types=1);

namespace Ovos\Store;

use Ovos\Store;
use Ovos\ArrayObject;
use Closure;

use function function_exists;
use function gzcompress;
use function gzuncompress;
use function is_array;
use function is_object;
use function serialize;
use function strlen;
use function substr;
use function unserialize;

/**
 * KeyValue
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
abstract class KeyValue extends Store
{
	// Prefixes
	public const string PREFIX_SERIALIZE = "\x01\xe4";
	public const string PREFIX_COMPRESS = ":\x1f\x8b";
	
	// Separators
	public const string SEPARATOR_PREFIX = ':';
	
	protected ?string $prefix = null;
	
	protected bool $compressionEnabled = true;
	
	protected int $compressionThreshold = 2048;
	
	// Groups
	public const string GROUP_DEFAULT = 'core';
	public const string GROUP_TESTS = 'tests';
	public const string GROUP_BENCHMARKS = 'benchmarks';
	
	protected ?string $group = self::GROUP_DEFAULT;
	
	protected ?ArrayObject $config = null;
	
	public function setPrefix(?string $prefix = null): static
	{
		$this->prefix = $prefix;
		
		return $this;
	}
	
	public function prefix(
		string $key,
		?string $prefix = null,
		string $separator = self::SEPARATOR_PREFIX
	): string
	{
		$prefix = $prefix ?? $this->prefix;
		
		return $prefix !== null
			? $prefix . $separator . $key
			: $key;
	}
	
	public function setGroup(
		?string $group,
	): static
	{
		$this->group = $group;
		
		return $this;
	}
	
	public function getGroup(): ?string
	{
		if($this->group !== null)
		{
			return $this->prefix($this->group);
		}
		
		return null;
	}
	
	public function setConfig(
		?ArrayObject $config,
	): static
	{
		$this->config = $config;
		
		return $this;
	}
	
	public function getConfig(): ?ArrayObject
	{
		return $this->config;
	}
	
	public function setCompression(
		ArrayObject $config,
	): static
	{
		if(($enabled = $config->offsetGet('enabled')) !== null) // true or false
		{
			$this->compressionEnabled = $enabled;
		}
		if(($threshold = $config->offsetGet('threshold')) !== null)
		{
			$this->compressionThreshold = $threshold;
		}
		
		return $this;
	}
	
	public function serialize(
		mixed $value,
	): ?string
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
	
	public function unserialize(
		?string $value,
	): mixed
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
	
	public function compress(
		?string $value,
	): ?string
	{
		if($value === null)
		{
			return null;
		}
		
		if($this->compressionEnabled !== true)
		{
			return $value;
		}
		
		if(strlen($value) < $this->compressionThreshold)
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
	
	public function decompress(
		?string $value,
	): ?string
	{
		if($value === null)
		{
			return null;
		}
		
		if($this->compressionEnabled !== true)
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
			
			// decompression failed
			if($value === false)
			{
				return null;
			}
		}
		
		return $value;
	}
	
	abstract public function get(
		string $key,
		?Closure $resolver = null,
		int $ttl = 0,
	): mixed;
	
	public function setFromResolver(
		string $key,
		?Closure $resolver,
		int $ttl = 0,
	): mixed
	{
		if($resolver === null)
		{
			return null;
		}
		
		$value = $resolver($this);
		$this->set($key, $value, $ttl);
		
		return $value;
	}
	
	public function callResolver(
		?Closure $resolver = null,
	): mixed
	{
		if($resolver === null)
		{
			return null;
		}
		
		return $resolver($this);
	}
	
	abstract public function set(
		string $key,
		mixed $value,
		int $ttl = 0,
	): bool;
}
