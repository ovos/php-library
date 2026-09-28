<?php
declare(strict_types=1);

namespace Ovos\Compression;

use Throwable;

use function gzdecode;
use function max;
use function str_starts_with;
use function strlen;

/**
 * gzip where the peer dictates it - a browser's CompressionStream upload, a
 * request body whose Content-Encoding the client chose, a download a third
 * party only offers gzipped. Read-only on purpose: where the server controls
 * both sides it writes Zstd (the house rule), so there is nothing to encode.
 *
 * Strict where Zstd is transparent: a value the peer called gzip that does
 * not start with the gzip magic answers null instead of passing through. A
 * caller that also accepts plain values checks MAGIC itself first.
 *
 * The cap is the point. A few hundred KB of deflate can inflate to hundreds
 * of MB, and gzdecode()'s own $max_length is only checked as its buffer
 * grows (on PHP 8.5, 100000 bytes came through a 90000 cap and 1000 through
 * 999), so the length is checked again after it: the answer is never longer
 * than $maxBytes.
 *
 * @author Marcin Gil <mg@ovos.at>
 */
final class Gzip
{
	/**
	 * Every gzip member starts with these two bytes (RFC 1952)
	 */
	public const string MAGIC = "\x1f\x8b";
	
	/**
	 * The inflated value, or null when $value is not a gzip member, does not
	 * decode or inflates past $maxBytes. A truncated or corrupt member makes
	 * gzdecode() warn, which an E_ALL handler turns into a throw out of the
	 * caller's request - it is swallowed here. Only the first member is read,
	 * as gzdecode() does
	 */
	public static function decode(
		string $value,
		int $maxBytes,
	): ?string
	{
		if(str_starts_with($value, self::MAGIC) === false)
		{
			return null;
		}
		
		try
		{
			// 0 is gzdecode()'s "unlimited" - a cap of nothing must not
			// become one
			$decoded = gzdecode($value, max(1, $maxBytes));
		}
		catch(Throwable)
		{
			return null;
		}
		
		return $decoded === false || strlen($decoded) > $maxBytes ? null : $decoded;
	}
}
