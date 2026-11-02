<?php
declare(strict_types=1);

namespace Ovos\Http;

use JsonException;

use function file_get_contents;
use function is_array;
use function json_decode;
use function strlen;

use const JSON_THROW_ON_ERROR;

/**
 * Request body reader for JSON APIs.
 *
 * @author Marcin Gil <mg@ovos.at>
 */
final class Body
{
	public const int MAX_BYTES = 1048576;
	
	/* A shared size scale to pass to raw()/json(), so callers name a tier
		instead of sprinkling magic byte counts. */
	/** 4 KiB — a single field or flag */
	public const int TINY = 4096;
	
	/** 16 KiB — a small form or object */
	public const int SMALL = 16384;
	
	/** 64 KiB — a query, note or recipient list */
	public const int MEDIUM = 65536;
	
	/** 256 KiB — a bulk id action or an intake batch */
	public const int BULK = 262144;
	
	/**
	 * The raw request body, or null when it exceeds $maxBytes.
	 */
	public static function raw(
		int $maxBytes = self::MAX_BYTES,
	): ?string
	{
		$body = (string)file_get_contents('php://input');
		
		if(strlen($body) > $maxBytes)
		{
			return null;
		}
		
		return $body;
	}
	
	/**
	 * The decoded JSON request body as an array, or null when missing, too
	 * large or malformed.
	 */
	public static function json(
		int $maxBytes = self::MAX_BYTES,
	): ?array
	{
		return self::decode(self::raw($maxBytes));
	}
	
	/**
	 * The request body as a typed Input reader - an EMPTY Input when the
	 * body is missing, too large or malformed, so the tolerant
	 * "json() ?? []" pattern becomes a single call with typed getters.
	 */
	public static function input(
		int $maxBytes = self::MAX_BYTES,
	): Input
	{
		return new Input(self::json($maxBytes) ?? []);
	}
	
	/**
	 * Decode a JSON string to an array, or null when empty, malformed or not a
	 * JSON object/array. Split out from json() so it stays testable without
	 * php://input.
	 */
	public static function decode(
		?string $body,
	): ?array
	{
		if($body === null || $body === '')
		{
			return null;
		}
		
		try
		{
			$data = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
		}
		catch(JsonException)
		{
			return null;
		}
		
		return is_array($data) ? $data : null;
	}
	
}
