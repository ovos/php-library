<?php
declare(strict_types=1);

namespace Ovos\Net;

use function explode;
use function inet_pton;
use function intdiv;
use function ord;
use function str_contains;
use function strlen;
use function strncmp;

/**
 * Does an address fall inside a CIDR range.
 *
 * Bitwise on the packed form, never on the string: "172.16." as a text prefix
 * misses 172.17–172.31, which is most of the range it looks like it covers, and
 * "10.0.0.1" as a prefix of "10.0.0.10" matches when it must not. inet_pton
 * gives 4 bytes for v4 and 16 for v6; the lengths differing is what keeps a v4
 * address from ever matching a v6 range.
 *
 * Two callers rely on this and both are security decisions — Client::getIp()
 * deciding whether the peer is a trusted proxy, and the uptime probe deciding
 * whether a destination is inside the network. One implementation, one set of
 * tests.
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Cidr
{
	/**
	 * @param string $address a plain IPv4/IPv6 address
	 * @param string $range CIDR ('10.0.0.0/8') or a single address
	 */
	public static function matches(
		string $address,
		string $range,
	): bool
	{
		if(str_contains($range, '/') === false)
		{
			return $address === $range;
		}
		
		[$subnet, $bits] = explode('/', $range, 2);
		
		$packedAddress = inet_pton($address);
		$packedSubnet = inet_pton($subnet);
		$bits = (int)$bits;
		
		if($packedAddress === false || $packedSubnet === false
			|| strlen($packedAddress) !== strlen($packedSubnet)
			|| $bits < 0 || $bits > strlen($packedSubnet) * 8)
		{
			return false;
		}
		
		$wholeBytes = intdiv($bits, 8);
		$remainingBits = $bits % 8;
		
		if($wholeBytes > 0
			&& strncmp($packedAddress, $packedSubnet, $wholeBytes) !== 0)
		{
			return false;
		}
		
		if($remainingBits === 0)
		{
			return true;
		}
		
		$mask = ~((1 << (8 - $remainingBits)) - 1) & 0xff;
		
		return (ord($packedAddress[$wholeBytes]) & $mask)
			=== (ord($packedSubnet[$wholeBytes]) & $mask);
	}
	
	/**
	 * @param string[] $ranges
	 */
	public static function matchesAny(
		string $address,
		array $ranges,
	): bool
	{
		foreach($ranges as $range)
		{
			if(self::matches($address, (string)$range))
			{
				return true;
			}
		}
		
		return false;
	}
}
