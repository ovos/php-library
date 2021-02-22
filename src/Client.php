<?php
declare(strict_types=1);

namespace Ovos;

/**
 * Client
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
class Client
{
	/**
	 * IP Address
	 *
	 * @var null|string
	 */
	protected static null|string $_ip = null;

	/**
	 * Returns visitors's IP address
	 * Supports proxies
	 * Supports cloudflare
	 *
	 * @return string
	 */
	public static function getIP(): string
	{
		if(self::$_ip === null)
		{
			// build up an array of available IPs
			$ips = [];

			// check headers sent by proxies

			// sometimes proxy sends original IP in this header
			if(!empty($_SERVER['HTTP_CLIENT_IP']))
			{
				$ips[] = $_SERVER['HTTP_CLIENT_IP'];
			}

			// sometimes proxy sends original IP in this header, sometimes with proxy IP following it
			if(!empty($_SERVER['HTTP_X_FORWARDED_FOR']))
			{
				$ips = array_merge($ips, explode(', ', $_SERVER['HTTP_X_FORWARDED_FOR']));
			}

			// look for the first valid IP
			foreach($ips as $possibleIp)
			{
				if(!preg_match('#^(10|172\.16|192\.168|127\.0)\.#', $possibleIp))
				{
					if(ip2long($possibleIp) !== false)
					{
						// valid IP
						self::$_ip = $possibleIp;
						break;
					}
				}
			}

			// no valid IP sent by proxies, use default
			if(!self::$_ip)
			{
				self::$_ip = $_SERVER['REMOTE_ADDR'] ?? '';
			}
		}

		return self::$_ip;
	}

	/**
	 * @return string
	 */
	public static function getUserAgent(): ?string
	{
		return $_SERVER['HTTP_USER_AGENT'] ?? null;
	}
}
