<?php
declare(strict_types=1);

namespace Ovos\Cache;

use Ovos\ArrayObject;

use function gzcompress;
use function gzuncompress;
use function function_exists;
use function strlen;
use function substr;
use function zstd_compress;
use function zstd_uncompress;

/**
 * Compressor
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Compressor
{
	// Prefixes
	public const string PREFIX_COMPRESS = ":\x1f\x8b";
	
	protected bool $compressionEnabled = true;
	
	protected int $compressionThreshold = 2048;
	
	public function __construct(
		?ArrayObject $config = null,
	)
	{
		$this->configure($config);
	}
	
	public function configure(?ArrayObject $config = null): static
	{
		if($config === null)
		{
			return $this;
		}
		
		if(isset($config->compression))
		{
			$this->setCompression($config->compression);
		}
		
		return $this;
	}
	
	public function setCompression(
		ArrayObject $config,
	): static
	{
		if(($enabled = $config->offsetGet('enabled')) !== null)
		{
			$this->compressionEnabled = $enabled;
		}
		if(($threshold = $config->offsetGet('threshold')) !== null)
		{
			$this->compressionThreshold = $threshold;
		}
		
		return $this;
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
			if(($compressed = zstd_compress($value, 4)) === false)
			{
				// compression failed
				return $value;
			}
			
			$value = 'zs' . static::PREFIX_COMPRESS . $compressed;
			unset($compressed);
		}
		else
		{
			if(($compressed = gzcompress($value, 3)) === false)
			{
				// compression failed
				return $value;
			}
			
			$value = 'gz' . static::PREFIX_COMPRESS . $compressed;
			unset($compressed);
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
		if($prefix === static::PREFIX_COMPRESS) // compressed
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
}
