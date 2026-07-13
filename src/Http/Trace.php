<?php
declare(strict_types=1);

namespace Ovos\Http;

use function bin2hex;
use function count;
use function explode;
use function preg_match;
use function random_bytes;
use function strtolower;
use function trim;

/**
 * Request-scoped trace correlation id.
 *
 * One id per request (or CLI process run): the trace id of an inbound
 * W3C traceparent header when the caller is instrumented (OTEL SDKs,
 * service meshes), a generated 32-hex id otherwise. Everything that
 * reports with it — the console sender, logs, sub-requests — becomes
 * correlatable as one transaction, across services when the header
 * propagates.
 *
 * @author Marcin Gil <mg@ovos.at>
 */
final class Trace
{
	protected static ?string $id = null;
	
	/**
	 * The request's trace id, memoized — inbound traceparent wins,
	 * otherwise generated once
	 */
	public static function id(): string
	{
		return self::$id ??=
			self::fromTraceparent((string)($_SERVER['HTTP_TRACEPARENT'] ?? ''))
			?? bin2hex(random_bytes(16));
	}
	
	/**
	 * The trace id of a W3C traceparent header
	 * ("00-<32 hex trace-id>-<16 hex parent-id>-<2 hex flags>"),
	 * or null when the header is absent or malformed
	 */
	public static function fromTraceparent(
		string $header,
	): ?string
	{
		$parts = explode('-', strtolower(trim($header)));
		if(count($parts) < 4)
		{
			return null;
		}
		
		[$version, $traceId, $parentId, $flags] = $parts;
		
		if(preg_match('~^[0-9a-f]{2}$~', $version) !== 1
			|| $version === 'ff' // forbidden by the spec
			|| preg_match('~^[0-9a-f]{32}$~', $traceId) !== 1
			|| $traceId === '00000000000000000000000000000000'
			|| preg_match('~^[0-9a-f]{16}$~', $parentId) !== 1
			|| $parentId === '0000000000000000'
			|| preg_match('~^[0-9a-f]{2}$~', $flags) !== 1)
		{
			return null;
		}
		
		return $traceId;
	}
	
	/**
	 * Drops the memoized id — a new one is derived on the next id() call
	 * (tests, or long-running CLI loops that want per-job correlation)
	 */
	public static function reset(): void
	{
		self::$id = null;
	}
}
