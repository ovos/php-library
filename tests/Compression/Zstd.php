<?php
declare(strict_types=1);

namespace Tests\Compression;

use Closure;
use Ovos\Compression\Zstd as BaseZstd;
use Ovos\Test;
use ReflectionProperty;

use function chr;
use function crc32;
use function hash;
use function hrtime;
use function intdiv;
use function memory_get_peak_usage;
use function memory_get_usage;
use function memory_reset_peak_usage;
use function ord;
use function pack;
use function random_bytes;
use function str_repeat;
use function str_split;
use function str_starts_with;
use function strlen;
use function strrev;
use function substr;
use function zstd_compress;
use function zstd_compress_add;
use function zstd_compress_init;

/**
 * Zstd - magic-discriminated at-rest framing for bulky stored values
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Zstd extends Test
{
	public function __construct()
	{
		if(BaseZstd::isAvailable() === false)
		{
			$this->setDisabled(true,
				'ext-zstd is not installed.'
			);
		}
	}
	
	public function compressesAboveThresholdAndRoundtrips(): bool
	{
		$value = str_repeat('{"message":"boom","backtrace":"#0 /srv/app/index.php(42)"}', 100);
		$compressed = BaseZstd::compress($value);
		
		return $compressed !== $value
			&& str_starts_with($compressed, BaseZstd::MAGIC)
			&& strlen($compressed) < strlen($value)
			&& BaseZstd::decompress($compressed) === $value;
	}
	
	public function keepsShortValuesRaw(): bool
	{
		return BaseZstd::compress('{"a":1}') === '{"a":1}';
	}
	
	public function passesLegacyPlainValuesThrough(): bool
	{
		return BaseZstd::decompress('[{"message":"boom"}]') === '[{"message":"boom"}]'
			&& BaseZstd::decompress('') === '';
	}
	
	public function corruptFrameYieldsEmpty(): bool
	{
		return BaseZstd::decompress(BaseZstd::MAGIC . 'not really a zstd frame') === '';
	}
	
	/**
	 * A raw value that itself starts with the frame magic must be framed
	 * whatever its size - stored raw it would be misread on decompress()
	 */
	public function framesAmbiguousRawValues(): bool
	{
		$value = BaseZstd::MAGIC . 'raw value that only looks like a frame';
		$stored = BaseZstd::compress($value);
		
		return $stored !== $value
			&& BaseZstd::decompress($stored) === $value;
	}
	
	public function keepsIncompressibleValuesRaw(): bool
	{
		$value = random_bytes(4096);
		
		return BaseZstd::compress($value) === $value;
	}
	
	/**
	 * A lower threshold frames values the default leaves raw - while the
	 * gained-nothing guard still keeps genuinely tiny values plain
	 */
	public function thresholdParameterLowersTheFramingBar(): bool
	{
		$value = str_repeat('{"a":1}', 128); // ~900B: raw by default, framed at 0
		
		return BaseZstd::compress($value) === $value
			&& str_starts_with(BaseZstd::compress($value, 0), BaseZstd::MAGIC)
			&& BaseZstd::decompress(BaseZstd::compress($value, 0)) === $value
			&& BaseZstd::compress('{"a":1}', 0) === '{"a":1}';
	}
	
	/**
	 * "At or below the threshold stays raw" is an inclusive floor, and the
	 * two tests either side of it never meet the boundary itself: every
	 * other value in the suite is far below or far above. A slipped
	 * comparison moves exactly one size class of values from raw to framed
	 * - invisible in every assertion here, and a redis-cli read of the
	 * smallest blob fields (the readability the threshold exists for) stops
	 * showing JSON.
	 */
	public function theThresholdIsAnInclusiveFloor(): bool
	{
		$atThreshold = str_repeat('a', BaseZstd::THRESHOLD);
		$overThreshold = str_repeat('a', BaseZstd::THRESHOLD + 1);
		
		return BaseZstd::compress($atThreshold) === $atThreshold
			&& str_starts_with(BaseZstd::compress($overThreshold), BaseZstd::MAGIC)
			&& BaseZstd::decompress(BaseZstd::compress($overThreshold)) === $overThreshold;
	}
	
	/**
	 * Only the LEADING four bytes discriminate a frame. A plain value that
	 * carries the magic bytes somewhere inside it - a scrubbed binary
	 * fragment, a base64 chunk in a request field - is still plain: read as
	 * a frame it fails to decompress and answers '', so the value is lost
	 * for good, and framed on write it would be stored unreadably. Both
	 * directions of the discrimination are the same rule.
	 */
	public function onlyALeadingMagicMeansAFrame(): bool
	{
		$value = '{"message":"boom' . BaseZstd::MAGIC . '"}';
		
		return BaseZstd::decompress($value) === $value
			&& BaseZstd::compress($value) === $value;
	}
	
	/**
	 * A frame whose header states 1 GiB, and whose 8192 RLE blocks hold it,
	 * in 32 KiB: inflated in one call it would reserve the gibibyte. Under a
	 * cap it is refused unread, as a real 4 MiB frame is under 1 MiB
	 */
	public function aBoundedReadRefusesAFrameStatingMoreThanItsCap(): bool
	{
		$gibibyte = self::rleFrame(1 << 30, 8192);
		$text = BaseZstd::compress(self::text(4 << 20));
		
		memory_reset_peak_usage();
		$before = memory_get_usage();
		$refused = BaseZstd::decompress($gibibyte, 1 << 20) === ''
			&& BaseZstd::decompress($text, 1 << 20) === '';
		$grew = memory_get_peak_usage() - $before;
		
		// the same frame shape, small enough to inflate: the frames are real
		return BaseZstd::decompress(self::rleFrame(2 << 20, 16), 2 << 20) === str_repeat("\0", 2 << 20)
			&& $refused
			&& $grew < 256 << 10;
	}
	
	/**
	 * A frame the encoder began without knowing its size states none: 64 MiB
	 * of zeros in a few KiB, refused once the output passes the cap - not
	 * after it was inflated whole
	 */
	public function aBoundedReadStopsAFrameThatStatesNoSizeAtItsCap(): bool
	{
		$frame = self::sizelessFrame(64);
		
		memory_reset_peak_usage();
		$before = memory_get_usage();
		$refused = BaseZstd::decompress($frame, 1 << 20) === '';
		$grew = memory_get_peak_usage() - $before;
		
		return (ord($frame[4]) & 0xe0) === 0
			&& $refused
			&& $grew < 4 << 20;
	}
	
	/**
	 * A frame that states its size, cut short: the walk refuses it where the
	 * cut is, and the blocks before the cut fall short of the size it states
	 * anyway
	 */
	public function aBoundedReadRefusesATruncatedFrame(): bool
	{
		$value = self::text(400000);
		$frame = BaseZstd::compress($value);
		
		return BaseZstd::decompress($frame, 1 << 20) === $value
			&& BaseZstd::decompress(substr($frame, 0, intdiv(strlen($frame), 2)), 1 << 20) === '';
	}
	
	/**
	 * The zstd CLI reading a pipe and zstd_compress_add() write frames that
	 * state no size, so nothing in such a header says the frame is cut
	 * short: the walk to its last block does, whether the cut falls between
	 * blocks or inside one
	 */
	public function aBoundedReadRefusesATruncatedFrameThatStatesNoSize(): bool
	{
		$value = self::text(400000);
		$frame = self::streamed($value);
		// no size, a 128 KiB window, a raw block, then a raw last block
		$handMade = BaseZstd::MAGIC . "\x00\x38" . self::rawBlock('hello', false) . self::rawBlock('world!', true);
		
		return (ord($frame[4]) & 0xe0) === 0
			&& BaseZstd::decompress($frame, 1 << 20) === $value
			&& BaseZstd::decompress(substr($frame, 0, intdiv(strlen($frame), 2)), 1 << 20) === ''
			&& BaseZstd::decompress(substr($frame, 0, -1), 1 << 20) === ''
			&& BaseZstd::decompress($handMade, 1 << 20) === 'helloworld!'
			// two bytes short of the last block: its first four bytes would inflate
			&& BaseZstd::decompress(substr($handMade, 0, -2), 1 << 20) === '';
	}
	
	/**
	 * A frame that states no size and spans several blocks reads back whole,
	 * up to a cap of exactly its length
	 */
	public function aBoundedReadInflatesAFrameThatStatesNoSize(): bool
	{
		$value = self::text(400000);
		$frame = self::streamed($value);
		
		return (ord($frame[4]) & 0xe0) === 0
			&& BaseZstd::decompress($frame, strlen($value)) === $value
			&& BaseZstd::decompress($frame, strlen($value) - 1) === '';
	}
	
	/**
	 * A value may be several frames end to end (RFC 8878, 3.1): each one is
	 * inflated, two that fit in a few bytes as two that span many blocks
	 */
	public function aBoundedReadInflatesEveryFrameOfARun(): bool
	{
		$first = self::text(5000);
		$second = substr(self::text(400000), 200000);
		$run = BaseZstd::compress($first, 0) . self::streamed($second) . BaseZstd::compress($first, 0);
		
		return BaseZstd::decompress(zstd_compress('hello') . zstd_compress('world'), 1 << 20) === 'helloworld'
			&& BaseZstd::decompress($run, 1 << 20) === $first . $second . $first;
	}
	
	/**
	 * A skippable frame - a magic of 0x184D2A50 to 0x184D2A5F, a length,
	 * then bytes no decoder reads - may lead the value or sit between frames
	 */
	public function aBoundedReadStepsOverSkippableFrames(): bool
	{
		$value = self::text(5000);
		$frame = BaseZstd::compress($value, 0);
		$skippable = "\x5e\x2a\x4d\x18" . pack('V', 6) . 'unread';
		
		return BaseZstd::decompress($skippable . $frame, 1 << 20) === $value
			&& BaseZstd::decompress($frame . $skippable . $frame, 1 << 20) === $value . $value
			&& BaseZstd::decompress($frame . substr($skippable, 0, -1), 1 << 20) === '';
	}
	
	/**
	 * The value must end where its last frame does: the bytes after it are
	 * no frame, and inflating up to them would drop them unseen
	 */
	public function aBoundedReadRefusesBytesAfterTheLastFrame(): bool
	{
		$value = self::text(5000);
		$frame = BaseZstd::compress($value, 0);
		
		return BaseZstd::decompress($frame, 1 << 20) === $value
			&& BaseZstd::decompress($frame . 'garbage', 1 << 20) === ''
			&& BaseZstd::decompress($frame . str_repeat("\x01", 600), 1 << 20) === ''
			&& BaseZstd::decompress($frame . substr($frame, 0, 3), 1 << 20) === '';
	}
	
	/**
	 * The cap counts what every frame inflates to: a frame stating its size
	 * is refused unread where it would pass what the frames before it left,
	 * a frame stating none once it does
	 */
	public function aBoundedReadCountsTheCapAcrossFrames(): bool
	{
		$zeros = self::sizelessFrame(1);
		$value = self::text(400000);
		$sized = BaseZstd::compress($value);
		$cap = (1 << 20) + strlen($value);
		
		return BaseZstd::decompress($zeros . $sized, $cap) === str_repeat("\0", 1 << 20) . $value
			&& BaseZstd::decompress($zeros . $sized, $cap - 1) === ''
			&& BaseZstd::decompress($sized . $zeros, $cap - 1) === '';
	}
	
	/**
	 * A frame that carries a checksum has it checked: its last four bytes
	 * are inflated with the rest, not dropped
	 */
	public function aBoundedReadChecksAFramesChecksum(): bool
	{
		$value = self::text(1000);
		$frame = self::checksummed($value);
		$damaged = substr($frame, 0, -1) . (substr($frame, -1) === 'x' ? 'y' : 'x');
		
		return BaseZstd::decompress($frame) === $value
			&& BaseZstd::decompress($frame, 1 << 20) === $value
			&& BaseZstd::decompress($damaged, 1 << 20) === ''
			&& BaseZstd::decompress(substr($frame, 0, -2), 1 << 20) === '';
	}
	
	/**
	 * A compressed block of ten bytes can regenerate a whole 128 KiB, so it
	 * goes to the decoder in a call of its own: the cap stops 2000 of them
	 * after a few, where counted by their bytes all 2000 would go in one
	 * call and inflate to 250 MiB before the cap is looked at
	 */
	public function aBoundedReadCountsACompressedBlockAsAWholeBlock(): bool
	{
		// what zstd_compress(str_repeat('ab', 65536)) writes as its block:
		// the literals 'ab', then one match at offset 2 to the end
		$block = "\x10\x61\x62\x01\x00\xfb\xff\xe5\x0e\x0b";
		// no size, a 128 KiB window; block headers: size 10, compressed, the last-block bit
		$frame = BaseZstd::MAGIC . "\x00\x38" . str_repeat("\x54\x00\x00" . $block, 1999) . "\x55\x00\x00" . $block;
		
		memory_reset_peak_usage();
		$before = memory_get_usage();
		$refused = BaseZstd::decompress($frame, 1 << 20) === '';
		$grew = memory_get_peak_usage() - $before;
		
		// the block alone is 128 KiB of 'ab': the frame is real
		return BaseZstd::decompress(BaseZstd::MAGIC . "\x00\x38\x55\x00\x00" . $block, 1 << 20) === str_repeat('ab', 65536)
			&& $refused
			&& $grew < 4 << 20;
	}
	
	/**
	 * Every zstd frame costs a decoder context of its own: a megabyte of
	 * nine-byte frames, each declaring a 128 MiB window, took seconds. A
	 * value of more than 64 frames, skippable ones counted, is refused
	 * before that; 64 still read
	 */
	public function aBoundedReadRefusesAValueOfManyFrames(): bool
	{
		// no size, a 128 MiB window, an empty raw last block
		$tiny = BaseZstd::MAGIC . "\x00\x88" . self::rawBlock('', true);
		$many = str_repeat($tiny, intdiv(1 << 20, strlen($tiny)));
		$x = (string)zstd_compress('x');
		$skippable = "\x50\x2a\x4d\x18" . pack('V', 0);
		
		$started = hrtime(true);
		$refused = BaseZstd::decompress($many, 1 << 20) === '';
		$elapsed = hrtime(true) - $started;
		
		return $refused
			&& $elapsed < 200000000
			&& BaseZstd::decompress(str_repeat($x, 64), 1 << 20) === str_repeat('x', 64)
			&& BaseZstd::decompress(str_repeat($x, 63) . $skippable, 1 << 20) === str_repeat('x', 63)
			&& BaseZstd::decompress(str_repeat($x, 65), 1 << 20) === ''
			&& BaseZstd::decompress(str_repeat($x, 64) . $skippable, 1 << 20) === '';
	}
	
	/**
	 * A frame header whose reserved bit is set (RFC 8878, 3.1.1.1.1) is
	 * refused: no decoder reads such a frame
	 */
	public function aBoundedReadRefusesAReservedHeaderBit(): bool
	{
		$value = self::text(5000);
		$frame = BaseZstd::compress($value, 0);
		$reserved = substr($frame, 0, 4) . chr(ord($frame[4]) | 0x08) . substr($frame, 5);
		
		return BaseZstd::decompress($frame, 1 << 20) === $value
			&& BaseZstd::decompress($reserved, 1 << 20) === '';
	}
	
	/**
	 * Without the streaming calls a capped read inflates a frame that states
	 * its size in one call, which reserves no more than that size
	 */
	public function withoutStreamingAFrameThatStatesItsSizeIsReadInOneCall(): bool
	{
		$value = self::text(400000);
		$frame = BaseZstd::compress($value);
		
		return self::withoutStreaming(static fn(): bool => BaseZstd::isBounded() === false
			&& BaseZstd::decompress($frame, strlen($value)) === $value
			&& BaseZstd::decompress($frame, strlen($value) - 1) === '');
	}
	
	/**
	 * Without the streaming calls a frame that states no size is refused:
	 * one call could inflate it without limit before its length is seen
	 */
	public function withoutStreamingAFrameThatStatesNoSizeIsRefused(): bool
	{
		$value = self::text(400000);
		$frame = self::streamed($value);
		
		return BaseZstd::decompress($frame, 1 << 20) === $value
			&& self::withoutStreaming(static fn(): bool => BaseZstd::decompress($frame, 1 << 20) === '');
	}
	
	/**
	 * Without the streaming calls every frame of a run is still read on its
	 * own, and the run must still end where its last frame does
	 */
	public function withoutStreamingEveryFrameOfARunIsRead(): bool
	{
		$first = self::text(5000);
		$second = substr(self::text(400000), 200000);
		$skippable = "\x5e\x2a\x4d\x18" . pack('V', 6) . 'unread';
		$run = BaseZstd::compress($first, 0) . $skippable . BaseZstd::compress($second, 0);
		
		return self::withoutStreaming(static fn(): bool => BaseZstd::decompress($run, 1 << 20) === $first . $second
			&& BaseZstd::decompress($run . 'garbage', 1 << 20) === '');
	}
	
	/**
	 * What a capped read takes for frames: a zstd frame's magic, or a
	 * skippable frame's, 0x184D2A50 to 0x184D2A5F little-endian - nothing
	 * either side of that range, nor a plain value
	 */
	public function isFramedKnowsBothKindsOfFrame(): bool
	{
		return BaseZstd::isFramed(BaseZstd::MAGIC . 'x')
			&& BaseZstd::isFramed("\x50\x2a\x4d\x18")
			&& BaseZstd::isFramed("\x5f\x2a\x4d\x18" . pack('V', 0))
			&& BaseZstd::isFramed("\x4f\x2a\x4d\x18") === false
			&& BaseZstd::isFramed("\x60\x2a\x4d\x18") === false
			&& BaseZstd::isFramed(substr(BaseZstd::MAGIC, 0, 3)) === false
			&& BaseZstd::isFramed('{"plain":1}') === false
			&& BaseZstd::isFramed('') === false;
	}
	
	public function aBoundedReadInflatesWhatFitsItsCap(): bool
	{
		$value = self::text(400000);
		$sizeless = self::sizelessFrame(2);
		
		return BaseZstd::decompress(BaseZstd::compress($value), strlen($value)) === $value
			&& BaseZstd::decompress(BaseZstd::compress($value), strlen($value) - 1) === ''
			&& BaseZstd::decompress($sizeless, 2 << 20) === str_repeat("\0", 2 << 20)
			&& BaseZstd::decompress('{"plain":1}', 1) === '{"plain":1}';
	}
	
	/**
	 * $mebibytes of zeros, framed by the streaming encoder in 1 MiB pieces
	 */
	protected static function sizelessFrame(
		int $mebibytes,
	): string
	{
		$context = zstd_compress_init(BaseZstd::LEVEL);
		$frame = '';
		for($i = 0; $i < $mebibytes; $i++)
		{
			$frame .= (string)zstd_compress_add($context, str_repeat("\0", 1 << 20), false);
		}
		
		return $frame . (string)zstd_compress_add($context, '', true);
	}
	
	/**
	 * $value framed by the streaming encoder in 64 KiB pieces: a frame that
	 * states no size
	 */
	protected static function streamed(
		string $value,
	): string
	{
		$context = zstd_compress_init(BaseZstd::LEVEL);
		$frame = '';
		foreach(str_split($value, 65536) as $piece)
		{
			$frame .= (string)zstd_compress_add($context, $piece, false);
		}
		
		return $frame . (string)zstd_compress_add($context, '', true);
	}
	
	/**
	 * A frame whose header states $stated bytes (a four-byte size, a 128 KiB
	 * window) and that holds $blocks RLE blocks of 128 KiB zeros
	 */
	protected static function rleFrame(
		int $stated,
		int $blocks,
	): string
	{
		$frame = BaseZstd::MAGIC . "\x80\x38" . pack('V', $stated);
		for($i = 1; $i <= $blocks; $i++)
		{
			// three bytes little-endian: the size, the RLE type, the last-block bit
			$frame .= substr(pack('V', (131072 << 3) | 2 | ($i === $blocks ? 1 : 0)), 0, 3) . "\0";
		}
		
		return $frame;
	}
	
	/**
	 * $value in one raw block of a frame that states its size and carries a
	 * checksum, the low four bytes of its XXH64 little-endian (RFC 8878,
	 * 3.1.1)
	 */
	protected static function checksummed(
		string $value,
	): string
	{
		// the descriptor: a four-byte size, a single segment, a checksum
		return BaseZstd::MAGIC . "\xa4" . pack('V', strlen($value))
			. self::rawBlock($value, true)
			. strrev(substr(hash('xxh64', $value, true), 4));
	}
	
	/**
	 * $bytes as a raw block: a three-byte little-endian header of the size,
	 * the raw type and the last-block bit, then the bytes
	 */
	protected static function rawBlock(
		string $bytes,
		bool $last,
	): string
	{
		return substr(pack('V', (strlen($bytes) << 3) | ($last ? 1 : 0)), 0, 3) . $bytes;
	}
	
	/**
	 * $read run as on an extension without the streaming calls, what the
	 * extension answers put back after
	 */
	protected static function withoutStreaming(
		Closure $read,
	): mixed
	{
		$bounded = new ReflectionProperty(BaseZstd::class, 'bounded');
		$was = $bounded->getValue();
		$bounded->setValue(null, false);
		try
		{
			return $read();
		}
		finally
		{
			$bounded->setValue(null, $was);
		}
	}
	
	/**
	 * $length bytes of varied words: compressible, and more than one 128 KiB
	 * block once past it
	 */
	protected static function text(
		int $length,
	): string
	{
		$text = '';
		for($i = 0; strlen($text) < $length; $i++)
		{
			$text .= 'word' . (crc32((string)$i) % 10000) . ' ';
		}
		
		return substr($text, 0, $length);
	}
}
