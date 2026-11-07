<?php
declare(strict_types=1);

namespace Tests\Cache;

use LengthException;
use Ovos\ArrayObject;
use Ovos\Cache\Compressor as Subject;
use Ovos\Cache\Serializer;
use Ovos\Compression\Zstd;
use Ovos\Test;
use Ovos\Test\Exception\SkipException;
use stdClass;

use function chr;
use function crc32;
use function gzcompress;
use function in_array;
use function intdiv;
use function memory_get_peak_usage;
use function memory_reset_peak_usage;
use function random_bytes;
use function serialize;
use function str_repeat;
use function strlen;
use function substr;
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
	
	/**
	 * Compression is a write setting: a reader with it off decodes what a
	 * writer with it on stored, and a writer with it off escapes a value that
	 * looks compressed, as one with it on does
	 */
	public function framedValuesDecodeWhateverTheWriteSetting(): bool
	{
		$on = new Subject;
		$off = (new Subject)->setCompression(new ArrayObject(['enabled' => false]));
		$long = str_repeat('compressible ', 400);
		$looking = 'gz' . Subject::PREFIX_COMPRESS . 'not compressed';
		
		return $off->decompress($on->compress($long)) === $long
			&& $off->decompress($off->compress($looking)) === $looking
			&& $on->decompress($off->compress($looking)) === $looking;
	}
	
	/**
	 * Framed bytes that do not decode are no value: a miss, never the bytes
	 */
	public function aValueThatDoesNotDecodeIsNoValue(): bool
	{
		$compressor = new Subject;
		
		return $compressor->decompress('gz' . Subject::PREFIX_COMPRESS . 'garbage') === null
			&& $compressor->decompress('zs' . Subject::PREFIX_COMPRESS . 'garbage') === null
			&& $compressor->decompress('xx' . Subject::PREFIX_COMPRESS . 'garbage') === null;
	}
	
	public function aLongValueIsCompressedAndReadsBack(): bool
	{
		$compressor = new Subject;
		$value = str_repeat('compressible ', 400);
		$stored = $compressor->compress($value);
		
		return strlen($stored) < strlen($value)
			&& $compressor->decompress($stored) === $value;
	}
	
	/**
	 * RULE: a value compression does not shrink (random bytes) is stored as
	 * it is - no frame that costs bytes and a decompression on every read
	 */
	public function anIncompressibleValueIsStoredAsItIs(): bool
	{
		$compressor = new Subject;
		$value = random_bytes(4096);
		$stored = $compressor->compress($value);
		$frames = [
			'zs' . Subject::PREFIX_COMPRESS,
			'gz' . Subject::PREFIX_COMPRESS,
		];
		
		return in_array(substr($stored, 0, 5), $frames, true) === false
			&& strlen($stored) <= strlen($value) + 5
			&& $compressor->decompress($stored) === $value;
	}
	
	/**
	 * RULE: a zstd frame whose header states more than MAX_BYTES is refused
	 * unread, nothing near that size allocated - zstd_uncompress() reserves
	 * the size a header states before anything else, a fatal error past the
	 * memory limit
	 */
	public function aFrameStatingMoreThanTheCapIsRefusedUnread(): bool
	{
		$this->requireZstd();
		
		$compressor = new Subject;
		// nine bytes: the magic, a single-segment descriptor, a content size
		// of 1 GiB
		$frame = "\x28\xb5\x2f\xfd\xa0\x00\x00\x00\x40";
		memory_reset_peak_usage();
		$before = memory_get_peak_usage();
		
		$value = $compressor->decompress('zs' . Subject::PREFIX_COMPRESS . $frame);
		
		return $value === null
			&& memory_get_peak_usage() - $before < 1048576;
	}
	
	/**
	 * RULE: a frame cut short, or followed by other bytes, is no value - an
	 * unbounded read hands back what the frame holds, a prefix of the value
	 * (or ''), as a hit
	 */
	public function aFrameCutShortIsNoValue(): bool
	{
		$this->requireZstd();
		
		$compressor = new Subject;
		$value = $this->largeValue();
		$stored = $compressor->compress($value);
		
		return substr($stored, 0, 2) === 'zs'
			&& $compressor->decompress(substr($stored, 0, intdiv(strlen($stored), 2))) === null
			&& $compressor->decompress(substr($stored, 0, -1)) === null
			&& $compressor->decompress($stored . 'more') === null;
	}
	
	/**
	 * RULE: a value of many zstd blocks reads back whole within the cap
	 */
	public function aLargeZstdValueReadsBackWhole(): bool
	{
		$this->requireZstd();
		
		$compressor = new Subject;
		$value = $this->largeValue();
		$stored = $compressor->compress($value);
		
		return substr($stored, 0, 2) === 'zs'
			&& strlen($stored) < strlen($value)
			&& $compressor->decompress($stored) === $value;
	}
	
	/**
	 * RULE: a gzip frame, the store's format without zstd, reads back
	 * through its bounded read
	 */
	public function aGzipValueReadsBack(): bool
	{
		$compressor = new Subject;
		$value = $this->largeValue();
		
		return $compressor->decompress('gz' . Subject::PREFIX_COMPRESS . gzcompress($value, 3)) === $value;
	}
	
	/**
	 * RULE: a value longer than MAX_BYTES is not cached at all - compress()
	 * throws, a store answers false and sends nothing (never the value raw,
	 * nor a frame no read takes back); one within it is compressed
	 */
	public function aValueLongerThanTheCapIsNotCached(): bool
	{
		// a cap small enough to test cheaply
		$compressor = new class extends Subject
		{
			public const int MAX_BYTES = 4096;
		};
		$within = str_repeat('compressible ', 300);
		$past = str_repeat('compressible ', 400);
		$stored = $compressor->compress($within);
		
		try
		{
			$compressor->compress($past);
			
			return false;
		}
		catch(LengthException)
		{
			return strlen($past) > 4096
				&& $compressor->decompress($stored) === $within;
		}
	}
	
	/**
	 * RULE: a gzip frame inflating far past MAX_BYTES is no value; one within
	 * it reads back (gzip's bound is about the cap, not exact)
	 */
	public function aGzipFrameFarPastTheCapIsNoValue(): bool
	{
		$compressor = new class extends Subject
		{
			public const int MAX_BYTES = 1048576;
		};
		$within = str_repeat('a', 1048575);
		$past = str_repeat('a', 4 * 1048576);
		
		return $compressor->decompress('gz' . Subject::PREFIX_COMPRESS . gzcompress($within, 3)) === $within
			&& $compressor->decompress('gz' . Subject::PREFIX_COMPRESS . gzcompress($past, 3)) === null;
	}
	
	/**
	 * Text of about 400 KB that spans several zstd blocks (128 KB each)
	 */
	protected function largeValue(): string
	{
		$value = '';
		for($index = 0; strlen($value) < 400000; $index++)
		{
			$value.= '<li data-id="' . crc32((string)$index) . '">' . str_repeat(chr(97 + $index % 26), $index % 40 + 1) . "</li>\n";
		}
		
		return $value;
	}
	
	protected function requireZstd(): void
	{
		if(Zstd::isAvailable() === false)
		{
			throw new SkipException('zstd is not installed');
		}
	}
}
