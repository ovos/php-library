<?php
declare(strict_types=1);

namespace Tests;

use Ovos\Client as Subject;
use Ovos\Test;

/**
 * Client::getIp() — which address the application believes.
 *
 * REMOTE_ADDR is the only thing the network guarantees; X-Forwarded-For,
 * Client-IP and X-Real-IP are claims by whoever connected. An earlier
 * implementation read the headers FIRST and filtered them with
 * `preg_match(...) === false`, which is never true — so every call fell through
 * to REMOTE_ADDR by accident, and repairing that comparison alone would have
 * trusted `Client-IP:` from anyone. On projects where an IP allowlist grants
 * access that is one header away from unauthenticated admin.
 *
 * A list of trusted proxies whose X-Forwarded-For was walked right to left
 * followed; no setup ever filled it — nginx hands PHP the visitor as
 * REMOTE_ADDR — and it went (codesafe docs/plans/client-ip-remote-addr.md).
 * These cases pin what is left: the connection's address, whatever a header
 * says.
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Client extends Test
{
	/** no forwarded header — whichever, from whichever connection — moves the address */
	public function forwardedHeadersNeverMoveTheAddress(): bool
	{
		$xff = $this->ip(['REMOTE_ADDR' => '203.0.113.9', 'HTTP_X_FORWARDED_FOR' => '198.51.100.123']);
		$legacy = $this->ip(['REMOTE_ADDR' => '203.0.113.9', 'HTTP_CLIENT_IP' => '198.51.100.123']);
		$real = $this->ip(['REMOTE_ADDR' => '203.0.113.9', 'HTTP_X_REAL_IP' => '198.51.100.123']);
		// a private peer with a chain is what a proxy looks like — still the peer
		$chain = $this->ip(['REMOTE_ADDR' => '10.0.0.5', 'HTTP_X_FORWARDED_FOR' => '198.51.100.123, 198.51.100.7']);
		
		return $xff === '203.0.113.9' && $legacy === '203.0.113.9' && $real === '203.0.113.9' && $chain === '10.0.0.5';
	}
	
	/** a CLI run has no connection, and a forwarded header does not invent one */
	public function noConnectionIsNoAddress(): bool
	{
		return $this->ip(['HTTP_X_FORWARDED_FOR' => '198.51.100.7']) === '';
	}
	
	/** the address is the request's: memoised, and reset() forgets it */
	public function resetClearsTheMemoizedAddress(): bool
	{
		Subject::reset();
		$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
		$first = Subject::getIp();
		$_SERVER['REMOTE_ADDR'] = '198.51.100.7';
		$memoised = Subject::getIp();
		Subject::reset();
		$after = Subject::getIp();
		unset($_SERVER['REMOTE_ADDR']);
		Subject::reset();
		
		return $first === '203.0.113.9' && $memoised === '203.0.113.9' && $after === '198.51.100.7';
	}

	/**
	 * NOTHING guarantees a $_SERVER key is present. REQUEST_SCHEME is absent on
	 * PHP's built-in server, on php-fpm behind an nginx that does not pass it,
	 * on every CLI run — and, per the method's own docblock, behind a TLS
	 * offloading load balancer, which is the case it was written for.
	 *
	 * Read unguarded it raised "Undefined array key", and an application that
	 * promotes warnings to exceptions turned that into a failed request. It
	 * surfaced inside the shutdown handler, where the second error buries the
	 * first.
	 */
	public function anAbsentSchemeIsPlainHttpNotAWarning(): bool
	{
		return $this->protocol([]) === Subject::PROTOCOL_HTTP;
	}
	
	public function directTlsIsRecognised(): bool
	{
		return $this->protocol(['REQUEST_SCHEME' => 'https']) === Subject::PROTOCOL_HTTPS
			&& $this->protocol(['REQUEST_SCHEME' => 'http']) === Subject::PROTOCOL_HTTP;
	}
	
	/** the offloading case: the forwarded header is the only evidence there is */
	public function offloadedTlsIsRecognisedFromTheForwardedHeader(): bool
	{
		return $this->protocol(['HTTP_X_FORWARDED_PROTO' => 'https']) === Subject::PROTOCOL_HTTPS
			&& $this->protocol(['REQUEST_SCHEME' => 'http',
				'HTTP_X_FORWARDED_PROTO' => 'https']) === Subject::PROTOCOL_HTTPS;
	}
	
	/**
	 * reset() has to clear the memoized protocol as well — it did not, so the
	 * first request a worker served decided the scheme for every one after it
	 */
	public function resetClearsTheMemoizedProtocol(): bool
	{
		$first = $this->protocol(['REQUEST_SCHEME' => 'https']);
		$second = $this->protocol(['REQUEST_SCHEME' => 'http']);
	
		return $first === Subject::PROTOCOL_HTTPS && $second === Subject::PROTOCOL_HTTP;
	}
	
	/**
	 * @param array<string, string> $server
	 */
	protected function protocol(
		array $server,
	): string
	{
		Subject::reset();
	
		unset($_SERVER['REQUEST_SCHEME'], $_SERVER['HTTP_X_FORWARDED_PROTO']);
	
		foreach($server as $key => $value)
		{
			$_SERVER[$key] = $value;
		}
	
		$protocol = Subject::getProtocol();
	
		unset($_SERVER['REQUEST_SCHEME'], $_SERVER['HTTP_X_FORWARDED_PROTO']);
		Subject::reset();
	
		return $protocol;
	}
	
	/**
	 * @param array<string, string> $server
	 */
	protected function ip(
		array $server,
	): string
	{
		$headers = ['REMOTE_ADDR', 'HTTP_X_FORWARDED_FOR', 'HTTP_CLIENT_IP', 'HTTP_X_REAL_IP'];
		Subject::reset();
		
		foreach($headers as $key)
		{
			unset($_SERVER[$key]);
		}
		
		foreach($server as $key => $value)
		{
			$_SERVER[$key] = $value;
		}
		
		$ip = Subject::getIp();
		
		foreach($headers as $key)
		{
			unset($_SERVER[$key]);
		}
		Subject::reset();
		
		return $ip;
	}
}
