<?php
declare(strict_types=1);

namespace Ovos;

use Throwable;

use function function_exists;
use function str_starts_with;
use function strlen;
use function zstd_compress;
use function zstd_uncompress;

/**
 * zstd at-rest compression for bulky, non-searchable stored values - blob
 * fields in redis, proxied response bodies, artifacts built once and read
 * many times. Values are discriminated by the zstd frame magic instead of a
 * flag byte, so values written before compression was switched on (JSON
 * always starts with { or [, js/map bodies are text) pass through
 * decompress() untouched - mixed old/new data coexists without migration.
 *
 * Deliberately not the cache's Compressor: that one owns the cache store's
 * own wire format, a self-describing prefix with a gzip fallback, and cannot
 * read a value that was written plain. Reach for it when the value lives in
 * the cache store, reach for this when the value is yours.
 *
 * House rule: zstd over gzip wherever the server controls both sides; gzip
 * stays only where the peer dictates it (browser CompressionStream uploads,
 * inbound bodies whose encoding the client chose).
 *
 * Without ext-zstd every value stays raw and every read still answers, so a
 * host that lacks the extension degrades instead of failing.
 *
 * @author Marcin Gil <mg@ovos.at>
 */
final class Zstd
{
	/**
	 * Every zstd frame starts with these four bytes (RFC 8878)
	 */
	public const string MAGIC = "\x28\xb5\x2f\xfd";
	
	/**
	 * Values at or below this many bytes stay raw - small fields remain
	 * redis-cli-readable and skip pointless CPU (mirrors the cache
	 * service's compression threshold)
	 */
	public const int THRESHOLD = 2048;
	
	/**
	 * zstd default level: fast with solid ratios on text/JSON
	 */
	public const int LEVEL = 3;
	
	protected static ?bool $available = null;
	
	public static function isAvailable(): bool
	{
		return self::$available ??= function_exists('zstd_compress');
	}
	
	/**
	 * The value compressed when that pays off, the raw value otherwise. A
	 * raw value that itself starts with the frame magic is ALWAYS framed,
	 * whatever its size - stored raw it would be misread on decompress()
	 *
	 * $level is for the rare value written ONCE and read many times - a
	 * nightly artifact downloaded by every consumer. Paying level 19 on one
	 * took it from 3.2 MB to 2.1 MB. Ordinary redis values keep the default:
	 * they are written as often as they are read, and the frame carries its
	 * own level, so a decompress never has to know.
	 */
	public static function compress(
		string $value,
		int $threshold = self::THRESHOLD,
		int $level = self::LEVEL,
	): string
	{
		$ambiguous = str_starts_with($value, self::MAGIC);
		
		if(self::isAvailable() === false
			|| ($ambiguous === false && strlen($value) <= $threshold))
		{
			return $value;
		}
		
		try
		{
			$compressed = zstd_compress($value, $level);
		}
		catch(Throwable)
		{
			$compressed = false;
		}
		
		if($compressed === false)
		{
			return $value;
		}
		
		// keep raw when compression gained nothing - except for ambiguous
		// values, which must go out framed to stay readable
		return $ambiguous === false && strlen($compressed) >= strlen($value)
			? $value
			: $compressed;
	}
	
	/**
	 * Transparent read: plain values pass through, framed values are
	 * decompressed; a corrupted frame yields '' (callers treat '' as
	 * empty/miss - an E_ALL handler would otherwise turn the decode
	 * warning into a throw)
	 */
	public static function decompress(
		string $value,
	): string
	{
		if(str_starts_with($value, self::MAGIC) === false)
		{
			return $value;
		}
		
		try
		{
			$decompressed = zstd_uncompress($value);
		}
		catch(Throwable)
		{
			return '';
		}
		
		return $decompressed === false ? '' : $decompressed;
	}
}
