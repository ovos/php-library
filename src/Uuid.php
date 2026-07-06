<?php
declare(strict_types=1);

namespace Ovos;

use function bin2hex;
use function chr;
use function ord;
use function preg_match;
use function random_bytes;
use function substr;

/**
 * Uuid
 *
 * Dependency-free RFC 9562 UUIDs: sixteen cryptographically random
 * bytes with the version and variant nibbles pinned - projects kept
 * pulling in a whole uuid library for exactly this.
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Uuid
{
	/**
	 * The canonical 8-4-4-4-12 form; input validation is
	 * case-insensitive per the RFC, output is always lowercase
	 */
	public const string PATTERN =
		'/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';
		
	/**
	 * A random (version 4) uuid, e.g. "0aea92ed-8c57-4144-85d0-1582257f4d67"
	 */
	public static function v4(): string
	{
		$bytes = random_bytes(16);
		$bytes[6] = chr(ord($bytes[6]) & 0x0f | 0x40); // version 4
		$bytes[8] = chr(ord($bytes[8]) & 0x3f | 0x80); // RFC variant (10xx)
		
		return self::format($bytes);
	}
	
	public static function isValid(
		string $uuid,
	): bool
	{
		return preg_match(self::PATTERN, $uuid) === 1;
	}
	
	/**
	 * Sixteen raw bytes in the canonical 8-4-4-4-12 form
	 */
	protected static function format(
		string $bytes,
	): string
	{
		$hex = bin2hex($bytes);
		
		return substr($hex, 0, 8)
			. '-' . substr($hex, 8, 4)
			. '-' . substr($hex, 12, 4)
			. '-' . substr($hex, 16, 4)
			. '-' . substr($hex, 20);
	}
}
