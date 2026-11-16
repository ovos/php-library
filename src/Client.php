<?php
declare(strict_types=1);

namespace Ovos;

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
			 *
			 * NOTHING guarantees REQUEST_SCHEME is set: the sentence above says
			 * so about load balancers, and PHP's built-in server, php-fpm behind
			 * an nginx without the fastcgi_param, and every CLI run omit it too.
			 * Read unguarded it warned, and an application that promotes warnings
			 * to exceptions turned a missing key into a failed request — in the
			 * shutdown handler, where the second error hides the first.
			 */
			$isHttps = ($_SERVER['REQUEST_SCHEME'] ?? '') === self::PROTOCOL_HTTPS
				|| ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === self::PROTOCOL_HTTPS;
			
			self::$protocol = $isHttps ? self::PROTOCOL_HTTPS : self::PROTOCOL_HTTP;
		}
		
		return self::$protocol;
	}
	
	/**
	 * IP address
	 */
	protected static ?string $ip = null;
	
	/**
	 * The visitor's IP address: the connection's own, REMOTE_ADDR — '' without
	 * one (a CLI run).
	 *
	 * X-Forwarded-For, Client-IP and X-Real-IP are claims by whoever connected
	 * and are never read: on projects where an IP allowlist grants access this
	 * function is an authentication input, and "first valid entry wins" over a
	 * forwarded header is the classic way to hand an attacker any address they
	 * like. A proxy in front (nginx) hands PHP the visitor as REMOTE_ADDR
	 * itself (real_ip), so no list of trusted proxies is kept here.
	 */
	public static function getIp(): string
	{
		return self::$ip ??= (string)($_SERVER['REMOTE_ADDR'] ?? '');
	}
	
	/**
	 * Test seam: the resolved address and the protocol are both cached
	 */
	public static function reset(): void
	{
		self::$ip = null;
		// the protocol is memoized too, and a reset that left it behind meant
		// the first request of a worker's life decided the scheme for all of them
		self::$protocol = null;
	}
	
	/**
	 * @return ?string
	 */
	public static function getUserAgent(): ?string
	{
		return $_SERVER['HTTP_USER_AGENT'] ?? null;
	}
}
