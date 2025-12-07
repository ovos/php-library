<?php
declare(strict_types=1);

namespace Ovos;

use function preg_match;
use function array_merge;
use function explode;
use function ip2long;

/**
 * Client
 *
 * @package Ovos
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
	 * Returns visitor's IP address
	 * Supports proxies
	 * Supports cloudflare
	 */
	public static function getIp(): string
	{
		if(self::$ip === null)
		{
			// build up an array of available IPs
			$ips = [];
			
			// check headers sent by proxies
			
			// sometimes proxy sends the original IP in this header
			if(!empty($_SERVER['HTTP_CLIENT_IP']))
			{
				$ips[] = $_SERVER['HTTP_CLIENT_IP'];
			}
			
			// sometimes proxy sends the original IP in this header, sometimes with proxy IP following it
			if(!empty($_SERVER['HTTP_X_FORWARDED_FOR']))
			{
				$ips = array_merge($ips, explode(', ', $_SERVER['HTTP_X_FORWARDED_FOR']));
			}
			
			// look for the first valid IP
			foreach($ips as $possibleIp)
			{
				if((preg_match('#^(10|172\.16|192\.168|127\.0)\.#', $possibleIp) === false)
					&& ip2long($possibleIp) !== false)
				{
					// valid IP
					self::$ip = $possibleIp;
					break;
				}
			}
			
			// no valid IP sent by proxies, use default or return an empty string
			if(self::$ip === null)
			{
				self::$ip = $_SERVER['REMOTE_ADDR'] ?? '';
			}
		}
		
		return self::$ip;
	}
	
	/**
	 * @return ?string
	 */
	public static function getUserAgent(): ?string
	{
		return $_SERVER['HTTP_USER_AGENT'] ?? null;
	}
}
