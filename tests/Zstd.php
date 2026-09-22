<?php
declare(strict_types=1);

namespace Tests;

use Ovos\Test;
use Ovos\Zstd as BaseZstd;

use function random_bytes;
use function str_repeat;
use function str_starts_with;
use function strlen;

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
}
