<?php
declare(strict_types=1);

namespace Tests\Compression;

use Ovos\Compression\Gzip as BaseGzip;
use Ovos\Test;

use function gzcompress;
use function gzencode;
use function str_repeat;
use function substr;

/**
 * Gzip - strict, capped decoding of gzip the peer dictates
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Gzip extends Test
{
	public function decodesAMember(): bool
	{
		$value = str_repeat('<div class="row">snapshot</div>', 100);
		
		return BaseGzip::decode((string)gzencode($value), 1048576) === $value
			&& BaseGzip::decode((string)gzencode(''), 1) === '';
	}
	
	/**
	 * Strict, unlike Zstd::decompress(): a value without the magic is not
	 * passed through - zlib-wrapped deflate included, which a client that
	 * says "gzip" can send by mistake
	 */
	public function aValueThatIsNotGzipIsNull(): bool
	{
		return BaseGzip::decode('<html>plain</html>', 1048576) === null
			&& BaseGzip::decode('', 1048576) === null
			&& BaseGzip::decode((string)gzcompress('zlib, not gzip'), 1048576) === null;
	}
	
	/**
	 * Under the E_ALL handler gzdecode()'s warning is a throw; decode()
	 * answers null instead of letting it out of the caller's request
	 */
	public function aTruncatedOrCorruptMemberIsNull(): bool
	{
		$member = (string)gzencode(str_repeat('abc', 1000));
		
		return BaseGzip::decode(substr($member, 0, 12), 1048576) === null
			&& BaseGzip::decode(BaseGzip::MAGIC . 'not really deflate', 1048576) === null;
	}
	
	/**
	 * gzdecode()'s own cap is checked as its buffer grows - 100000 bytes
	 * came through a 90000 cap on PHP 8.5 - so the answer is held to
	 * $maxBytes exactly, on both sides of the boundary
	 */
	public function theCapIsExact(): bool
	{
		$value = str_repeat('a', 100000);
		$member = (string)gzencode($value);
		
		return BaseGzip::decode($member, 100000) === $value
			&& BaseGzip::decode($member, 99999) === null
			&& BaseGzip::decode($member, 90000) === null;
	}
	
	/**
	 * 0 is gzdecode()'s "unlimited"; here it is a cap of nothing, so a
	 * caller whose cap came out wrong decodes no bomb
	 */
	public function aCapOfNothingIsNotUnlimited(): bool
	{
		return BaseGzip::decode((string)gzencode('a'), 0) === null
			&& BaseGzip::decode((string)gzencode('a'), -1) === null;
	}
}
