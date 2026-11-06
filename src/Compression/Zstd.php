<?php
declare(strict_types=1);

namespace Ovos\Compression;

use Generator;
use Throwable;
use UnexpectedValueException;

use function function_exists;
use function is_array;
use function is_int;
use function iterator_count;
use function max;
use function ord;
use function str_starts_with;
use function strlen;
use function substr;
use function unpack;
use function zstd_compress;
use function zstd_uncompress;
use function zstd_uncompress_add;
use function zstd_uncompress_init;

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
 * inbound bodies whose encoding the client chose) - and is read there
 * through Gzip, never written.
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
	
	/**
	 * The most a block holds, compressed or regenerated (RFC 8878, 3.1.1.2)
	 */
	protected const int BLOCK_MAX = 131072;
	
	/**
	 * The longest frame header: magic, descriptor, window, a four-byte
	 * dictionary id and an eight-byte content size
	 */
	protected const int HEADER_MAX = 18;
	
	/**
	 * The most frames a capped read takes, skippable ones counted: a sender
	 * writes one, and every zstd frame costs a decoder context of its own -
	 * a megabyte of tiny frames would cost seconds
	 */
	protected const int FRAMES_MAX = 64;
	
	protected static ?bool $available = null;
	
	protected static ?bool $bounded = null;
	
	public static function isAvailable(): bool
	{
		return self::$available ??= function_exists('zstd_compress');
	}
	
	/**
	 * Whether a capped decompress() reads a frame that states no size: the
	 * streaming calls inflate it a block's worth at a time, without them
	 * such a frame is refused. For a caller deciding whether to accept zstd
	 * from outside at all
	 */
	public static function isBounded(): bool
	{
		return self::$bounded ??= function_exists('zstd_uncompress_init') && function_exists('zstd_uncompress_add');
	}
	
	/**
	 * Whether $value begins with a frame a capped decompress() reads: a zstd
	 * frame or a skippable one (RFC 8878, 3.1)
	 */
	public static function isFramed(
		string $value,
	): bool
	{
		return str_starts_with($value, self::MAGIC) || self::isSkippable($value, 0);
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
	 *
	 * $maxBytes bounds what a value from outside may inflate to - a request
	 * body, a feed. zstd_uncompress() reserves the size a frame's header
	 * states before it inflates anything, so nine bytes stating 1 GiB end
	 * the request, and a frame that states no size inflates without limit.
	 * With a cap the value is read as frames end to end (RFC 8878, 3.1), at
	 * most FRAMES_MAX of them, skippable frames stepped over wherever they
	 * sit, and yields '' unless every frame is whole and the value ends
	 * where the last one does: a frame stating more than the cap leaves is
	 * refused unread, every other is inflated in calls that each add at most
	 * one block's worth of output, and refused once the output passes the
	 * cap or differs from the size its header states. Without a cap - values
	 * this application wrote itself - the frame is inflated in one call, as
	 * it always was
	 */
	public static function decompress(
		string $value,
		?int $maxBytes = null,
	): string
	{
		if($maxBytes !== null)
		{
			return self::isFramed($value)
				? self::bounded($value, max(0, $maxBytes))
				: $value;
		}
		
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
	
	/**
	 * A run of frames inflated to at most $maxBytes in all, '' where it would
	 * be more, is not whole frames end to end or does not decode
	 */
	protected static function bounded(
		string $value,
		int $maxBytes,
	): string
	{
		$length = strlen($value);
		$decompressed = '';
		$frames = 0;
		try
		{
			// frames end to end: bytes where no whole frame begins throw
			for($offset = 0; $offset < $length; $offset = $end)
			{
				$frames++;
				if($frames > self::FRAMES_MAX)
				{
					return '';
				}
				
				if(self::isSkippable($value, $offset))
				{
					$end = self::skippableEnd($value, $offset);
					continue;
				}
				
				$inflated = self::inflate($value, $offset, $maxBytes - strlen($decompressed));
				if($inflated === false)
				{
					return '';
				}
				
				[$frame, $end] = $inflated;
				$decompressed .= $frame;
			}
		}
		catch(Throwable)
		{
			return '';
		}
		
		return $decompressed;
	}
	
	/**
	 * The zstd frame at $offset inflated into at most $room bytes, and to
	 * exactly the size its header states where it states one, with where the
	 * frame ends; false where it would be more, comes out different or does
	 * not decode
	 *
	 * @return array{string, int}|false
	 */
	protected static function inflate(
		string $value,
		int $offset,
		int $room,
	): array|false
	{
		// refused unread: nothing is inflated for a frame stating more than
		// the cap leaves after the frames before it
		$stated = self::statedSize(substr($value, $offset, self::HEADER_MAX));
		if($stated !== null && $stated > $room)
		{
			return false;
		}
		
		$parts = self::parts($value, $offset);
		$frame = self::isBounded()
			? self::inflateInParts($value, $parts, $stated ?? $room)
			: self::inflateWhole($value, $offset, $parts, $stated);
		if($frame === false || ($stated !== null && strlen($frame) !== $stated))
		{
			return false;
		}
		
		return [$frame, $parts->getReturn()];
	}
	
	/**
	 * Inflates a frame one part per call, in a context of its own: a call
	 * never reaches past the frame, and adds one block's worth of output at
	 * most; false once the output passes $bound or a part does not decode
	 *
	 * @param Generator<int, array{int, int}, mixed, int> $parts
	 */
	protected static function inflateInParts(
		string $value,
		Generator $parts,
		int $bound,
	): string|false
	{
		$context = zstd_uncompress_init();
		if($context === false)
		{
			return false;
		}
		
		$frame = '';
		foreach($parts as [$start, $length])
		{
			// a frame from outside that does not decode warns first: expected here
			$piece = @zstd_uncompress_add($context, substr($value, $start, $length));
			if($piece === false)
			{
				return false;
			}
			
			$frame .= $piece;
			if(strlen($frame) > $bound)
			{
				return false;
			}
		}
		
		return $frame;
	}
	
	/**
	 * For an extension without the streaming calls: one call, and only for a
	 * frame that states its size - one that does not could inflate without
	 * limit before the length is seen
	 *
	 * @param Generator<int, array{int, int}, mixed, int> $parts
	 */
	protected static function inflateWhole(
		string $value,
		int $offset,
		Generator $parts,
		?int $stated,
	): string|false
	{
		if($stated === null)
		{
			return false;
		}
		
		// walked first: one call reads exactly the frame, nothing after it
		iterator_count($parts);
		
		return @zstd_uncompress(substr($value, $offset, $parts->getReturn() - $offset));
	}
	
	/**
	 * Where the skippable frame at $offset ends, by the length it states
	 * (RFC 8878, 3.1.2); throws where it is cut short
	 */
	protected static function skippableEnd(
		string $value,
		int $offset,
	): int
	{
		$field = strlen($value) >= $offset + 8 ? unpack('V', $value, $offset + 4) : false;
		$end = is_array($field) && is_int($field[1] ?? null) ? $offset + 8 + $field[1] : -1;
		if($end < 0 || $end > strlen($value))
		{
			throw new UnexpectedValueException('A skippable frame is cut short.');
		}
		
		return $end;
	}
	
	/**
	 * The zstd frame at $offset in the parts a bounded read inflates one call
	 * each, as [offset, length]: its header with the first block, each
	 * further block, then the checksum (RFC 8878, 3.1.1). Raw and RLE blocks
	 * go out together while what they add stays within one block's worth,
	 * so a run of small or empty ones costs one call; a compressed block
	 * counts as a whole block however small, and so costs a call of its
	 * own. Returns where the frame ends; throws where it is cut short or
	 * malformed
	 *
	 * @return Generator<int, array{int, int}, mixed, int>
	 */
	protected static function parts(
		string $value,
		int $offset,
	): Generator
	{
		$length = strlen($value);
		if(substr($value, $offset, 4) !== self::MAGIC || $offset + 5 >= $length)
		{
			throw new UnexpectedValueException('No zstd frame begins here.');
		}
		
		// a reserved bit set: no decoder reads the frame
		$descriptor = ord($value[$offset + 4]);
		if(($descriptor & 0x08) !== 0)
		{
			throw new UnexpectedValueException('A reserved frame header bit is set.');
		}
		
		// past the descriptor: the window byte, absent in a single segment,
		// the dictionary id and the content size
		$singleSegment = ($descriptor >> 5) & 1;
		$at = $offset + 5 + (1 - $singleSegment) + [0, 1, 2, 4][$descriptor & 0x03]
			+ [$singleSegment, 2, 4, 8][$descriptor >> 6];
		$start = $offset;
		// what the blocks gathered since $start may add to the output
		$run = 0;
		do
		{
			if($at + 3 > $length)
			{
				throw new UnexpectedValueException('A block header is cut short.');
			}
			
			// three bytes little-endian: the last-block bit, two bits of type, the size
			$block = ord($value[$at]) | (ord($value[$at + 1]) << 8) | (ord($value[$at + 2]) << 16);
			$type = ($block >> 1) & 0x03;
			$size = $block >> 3;
			if($type === 3 || $size > self::BLOCK_MAX)
			{
				throw new UnexpectedValueException('A block is of the reserved type or too large.');
			}
			
			// a raw or RLE block adds its size, a compressed one up to a whole
			// block: the gathered blocks go out first where this one would take
			// their output past a block's worth, or their bytes already are
			$adds = $type === 2 ? self::BLOCK_MAX : $size;
			if($run + $adds > self::BLOCK_MAX || $at - $start >= self::BLOCK_MAX)
			{
				yield [$start, $at - $start];
				$start = $at;
				$run = 0;
			}
			
			// an RLE block holds the one byte it repeats $size times
			$run += $adds;
			$at += 3 + ($type === 1 ? 1 : $size);
			if($at > $length)
			{
				throw new UnexpectedValueException('A block is cut short.');
			}
		}
		while(($block & 1) === 0);
		
		yield [$start, $at - $start];
		
		// the content checksum, where the descriptor announces one
		if(($descriptor & 0x04) !== 0)
		{
			if($at + 4 > $length)
			{
				throw new UnexpectedValueException('The checksum is cut short.');
			}
			
			yield [$at, 4];
			$at += 4;
		}
		
		return $at;
	}
	
	/**
	 * Whether a skippable frame begins at $offset: its magic is 0x184D2A50
	 * to 0x184D2A5F, little-endian (RFC 8878, 3.1.2)
	 */
	protected static function isSkippable(
		string $value,
		int $offset,
	): bool
	{
		return substr($value, $offset + 1, 3) === "\x2a\x4d\x18"
			&& (ord($value[$offset]) & 0xf0) === 0x50;
	}
	
	/**
	 * The content size a frame's header states, null where it states none
	 * or is too short to say (RFC 8878, 3.1.1.1)
	 */
	protected static function statedSize(
		string $frame,
	): ?int
	{
		if(strlen($frame) < 5)
		{
			return null;
		}
		
		$descriptor = ord($frame[4]);
		$singleSegment = ($descriptor >> 5) & 1;
		$sizeBytes = [$singleSegment, 2, 4, 8][$descriptor >> 6];
		// past the descriptor: the window byte, absent in a single segment,
		// and the dictionary id
		$offset = 5 + (1 - $singleSegment) + [0, 1, 2, 4][$descriptor & 0x03];
		if($sizeBytes === 0 || strlen($frame) < $offset + $sizeBytes)
		{
			return null;
		}
		
		$field = unpack([1 => 'C', 2 => 'v', 4 => 'V', 8 => 'P'][$sizeBytes], $frame, $offset);
		$size = is_array($field) && is_int($field[1] ?? null) ? $field[1] : -1;
		if($size < 0)
		{
			return null;
		}
		
		// a two-byte field counts from 256
		return $sizeBytes === 2 ? $size + 256 : $size;
	}
}
