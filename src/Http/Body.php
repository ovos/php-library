<?php
declare(strict_types=1);

namespace Ovos\Http;

use JsonException;

use function array_slice;
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
	
	/**
	 * A capped list of positive int ids from a decoded body carrying either a
	 * single {id} or an {ids: […]} list — the bulk-id selection for JSON APIs.
	 */
	public static function ids(
		array $body,
		int $max = 100,
		string $singleKey = 'id',
		string $listKey = 'ids',
	): array
	{
		$raw = is_array($body[$listKey] ?? null)
			? $body[$listKey]
			: [$body[$singleKey] ?? 0];
		
		$ids = [];
		foreach(array_slice($raw, 0, $max) as $value)
		{
			if((int)$value > 0)
			{
				$ids[] = (int)$value;
			}
		}
		
		return $ids;
	}
}
