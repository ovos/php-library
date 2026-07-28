<?php
declare(strict_types=1);

namespace Ovos;

use Ovos\Net\Cidr;
use Throwable;

use function array_map;
use function array_reverse;
use function explode;
use function filter_var;
use function Ovos\container;
use function str_starts_with;
use function strpos;
use function strval;
use function substr;
use function substr_count;
use function trim;

use const FILTER_VALIDATE_IP;

/**
 * Client
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Client
{
	// Protocol constant
	public const string PROTOCOL_HTTP = 'http';
	public const string PROTOCOL_HTTPS = 'https';
	
	/**
	 * Returns current protocol (http or https)
	 */
	protected static ?string $protocol = null;
	
	/**
	 * Is HTTPS on?
	 */
	public static function getProtocol(): string
	{
		if(self::$protocol === null)
		{
			/**
			 * REQUEST_SCHEME have been available since Apache 2.4.16, but only on servers with direct TLS connections
			 * - not present on servers with load-balancer + TLS offloading
			 * (TLS offloaded at the load-balancer and then forwarded to the worker-nodes via http)
			 * 
			 * For setups with load-balancer or cloudflare, we need to rely on HTTP_X_FORWARDED_PROTO
			 * HTTPS header is not reliable, sometimes it's "on" even on HTTP
			 */
			$isHttps = $_SERVER['REQUEST_SCHEME'] === self::PROTOCOL_HTTPS
				|| (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === self::PROTOCOL_HTTPS);
			
			self::$protocol = $isHttps ? self::PROTOCOL_HTTPS : self::PROTOCOL_HTTP;
		}
		
		return self::$protocol;
	}

	/**
	 * IP address
	 */
	protected static ?string $ip = null;
	
	/**
	 * Networks whose forwarded headers may be believed, as CIDR strings.
	 * null = not read yet; [] = trust nothing, which is the default and makes
	 * getIp() behave exactly like REMOTE_ADDR.
	 *
	 * @var string[]|null
	 */
	protected static ?array $trustedProxies = null;
	
	/**
	 * @param string[] $proxies CIDRs or plain addresses
	 */
	public static function setTrustedProxies(
		array $proxies,
	): void
	{
		self::$trustedProxies = $proxies;
		self::$ip = null;
	}
	
	/**
	 * system.trusted_proxies, read once. Absent config means an empty list:
	 * an app that never heard of this keeps the peer address.
	 *
	 * @return string[]
	 */
	public static function getTrustedProxies(): array
	{
		if(self::$trustedProxies === null)
		{
			$configured = null;
			
			try
			{
				$configured = container()
					->get(Application::CONTAINER_KEY_CONFIG)
					?->getPath(['system', 'trusted_proxies']);
			}
			catch(Throwable)
			{
				// no application booted (a unit test, a bare script)
			}
			
			self::$trustedProxies = $configured instanceof ArrayObject
				? array_map(strval(...), $configured->getArrayCopy())
				: [];
		}
		
		return self::$trustedProxies;
	}
	
	/**
	 * The visitor's IP address.
	 *
	 * REMOTE_ADDR is the only address the network guarantees; X-Forwarded-For
	 * is a claim by whoever connected. So a forwarded header is read ONLY when
	 * the peer is a configured proxy, and the chain is walked RIGHT TO LEFT —
	 * the left-most entry is the one the client wrote, which is why
	 * "first valid entry wins" is the classic way to hand an attacker any
	 * address they like.
	 *
	 * With no trusted proxies configured this returns REMOTE_ADDR and no header
	 * can change that. That is deliberate: on projects where an IP allowlist
	 * grants access, this function is an authentication input.
	 *
	 * HTTP_CLIENT_IP is not consulted at all — a single-valued legacy header
	 * with no chain semantics has no safe reading.
	 */
	public static function getIp(): string
	{
		if(self::$ip !== null)
		{
			return self::$ip;
		}
		
		$remote = (string)($_SERVER['REMOTE_ADDR'] ?? '');
		$trusted = self::getTrustedProxies();
		
		if($trusted === [] || self::isTrustedProxy($remote) === false)
		{
			return self::$ip = $remote;
		}
		
		$chain = explode(',', (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''));
		
		foreach(array_reverse($chain) as $hop)
		{
			$hop = self::normalizeAddress($hop);
			
			// a malformed hop means the chain cannot be reasoned about past
			// this point — stop rather than skip, or a bogus entry becomes a
			// way to hide the hops behind it
			if(filter_var($hop, FILTER_VALIDATE_IP) === false)
			{
				break;
			}
			
			if(self::isTrustedProxy($hop) === false)
			{
				return self::$ip = $hop;
			}
		}
		
		return self::$ip = $remote;
	}
	
	/**
	 * Strips what proxies really put in a chain: a port (192.0.2.1:52631),
	 * the brackets of an IPv6 literal ([2001:db8::1]:443), and surrounding
	 * whitespace. RFC 7239 placeholders ("unknown", "_hidden") fall out on
	 * their own — they do not validate as addresses.
	 */
	protected static function normalizeAddress(
		string $address,
	): string
	{
		$address = trim($address);
		
		if(str_starts_with($address, '['))
		{
			$closing = strpos($address, ']');
			
			return $closing === false
				? ''
				: substr($address, 1, $closing - 1);
		}
		
		// IPv4 with a port; a bare IPv6 has many colons and keeps them all
		if(substr_count($address, ':') === 1)
		{
			return substr($address, 0, (int)strpos($address, ':'));
		}
		
		return $address;
	}
	
	/**
	 * Is this address one of the configured proxies? Accepts a plain address
	 * or a CIDR, v4 and v6.
	 */
	public static function isTrustedProxy(
		string $address,
	): bool
	{
		if(filter_var($address, FILTER_VALIDATE_IP) === false)
		{
			return false;
		}
		
		foreach(self::getTrustedProxies() as $trusted)
		{
			if(self::inRange($address, $trusted))
			{
				return true;
			}
		}
		
		return false;
	}
	
	/**
	 * Whether an address falls inside a CIDR range or equals a plain one.
	 * The matching itself lives in Net\Cidr - the uptime probe needs the
	 * same decision, and security logic should exist once.
	 */
	protected static function inRange(
		string $address,
		string $range,
	): bool
	{
		return Cidr::matches($address, $range);
	}
	
	/**
	 * Test seam: the resolved address and the proxy list are both cached
	 */
	public static function reset(): void
	{
		self::$ip = null;
		self::$trustedProxies = null;
	}
	
	/**
	 * @return ?string
	 */
	public static function getUserAgent(): ?string
	{
		return $_SERVER['HTTP_USER_AGENT'] ?? null;
	}
}
