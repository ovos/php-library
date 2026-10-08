<?php
declare(strict_types=1);

namespace Tests\Cache;

use Ovos\Cache\Compressor as Subject;
use Ovos\Cache\Serializer;
use Ovos\Compression\Zstd;
use Ovos\Test;
use Ovos\Test\Exception\SkipException;
use stdClass;

use function gzcompress;
use function serialize;
use function str_repeat;
use function strlen;
use function zstd_compress;

/**
 * Compressor - a value at or above the threshold is compressed and marked,
 * a shorter one stored as it is. A read must never take an uncompressed
 * value for compressed data: one that carries the marker is marked as
 * stored raw, or a short string an outsider wrote would be "decompressed"
 * into whatever they packed inside it - serialized objects among them
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Compressor extends Test
{
	public function aShortValueThatLooksGzippedReadsBackAsItself(): bool
	{
		$compressor = new Subject;
		$value = 'gz' . Subject::PREFIX_COMPRESS . gzcompress(Serializer::PREFIX_SERIALIZE . serialize(new stdClass));
		
		return strlen($value) < 2048
			&& $compressor->decompress($compressor->compress($value)) === $value;
	}
	
	public function aShortValueThatLooksZstdCompressedReadsBackAsItself(): bool
	{
		if(Zstd::isAvailable() === false)
		{
			throw new SkipException('zstd is not installed');
		}
		
		$compressor = new Subject;
		$value = 'zs' . Subject::PREFIX_COMPRESS . zstd_compress(Serializer::PREFIX_SERIALIZE . serialize(new stdClass));
		
		return $compressor->decompress($compressor->compress($value)) === $value;
	}
	
	public function aShortPlainValueIsStoredAsItIs(): bool
	{
		$compressor = new Subject;
		
		return $compressor->compress('plain') === 'plain'
			&& $compressor->decompress('plain') === 'plain';
	}
	
	public function aLongValueIsCompressedAndReadsBack(): bool
	{
		$compressor = new Subject;
		$value = str_repeat('compressible ', 400);
		$stored = $compressor->compress($value);
		
		return strlen($stored) < strlen($value)
			&& $compressor->decompress($stored) === $value;
	}
}
