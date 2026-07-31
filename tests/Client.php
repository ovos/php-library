<?php
declare(strict_types=1);

namespace Tests;

use Ovos\Client as Subject;
use Ovos\Test;

/**
 * Client::getIp() — which address the application believes.
 *
 * REMOTE_ADDR is the only thing the network guarantees; X-Forwarded-For is a
 * claim by whoever connected. The previous implementation read the headers
 * FIRST and filtered them with `preg_match(...) === false`, which is never true
 * (preg_match answers 0 or 1, and false only for an invalid pattern) — so the
 * loop never assigned and every call fell through to REMOTE_ADDR by accident.
 * Repairing that comparison alone would have trusted `Client-IP:` from anyone,
 * and on projects where an IP allowlist grants access that is one header away
 * from unauthenticated admin.
 *
 * So the rule is the trusted-proxy one, and these cases pin it: nothing is
 * believed unless the PEER is a configured proxy, and the chain is read right
 * to left because the left-most entry is the one the client wrote.
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Client extends Test
{
	/** the default: no proxies configured, so no header can move the address */
	public function withoutTrustedProxiesHeadersAreIgnored(): bool
	{
		$xff = $this->ip(['REMOTE_ADDR' => '203.0.113.9',
			'HTTP_X_FORWARDED_FOR' => '91.225.132.123'], []);
		$legacy = $this->ip(['REMOTE_ADDR' => '203.0.113.9',
			'HTTP_CLIENT_IP' => '91.225.132.123'], []);

		return $xff === '203.0.113.9' && $legacy === '203.0.113.9';
	}

	/** a stranger claiming to be forwarded is still a stranger */
	public function untrustedPeerCannotForward(): bool
	{
		return $this->ip(['REMOTE_ADDR' => '203.0.113.9',
			'HTTP_X_FORWARDED_FOR' => '91.225.132.123'], ['10.0.0.0/8'])
			=== '203.0.113.9';
	}

	public function trustedPeerRevealsTheClient(): bool
	{
		return $this->ip(['REMOTE_ADDR' => '10.0.0.5',
			'HTTP_X_FORWARDED_FOR' => '198.51.100.7'], ['10.0.0.0/8'])
			=== '198.51.100.7';
	}

	/**
	 * The attack the right-to-left walk exists for: the client prepends an
	 * address of its choosing, the proxy appends the real one
	 */
	public function leftmostEntryIsNotBelieved(): bool
	{
		return $this->ip(['REMOTE_ADDR' => '10.0.0.5',
			'HTTP_X_FORWARDED_FOR' => '91.225.132.123, 198.51.100.7'],
			['10.0.0.0/8']) === '198.51.100.7';
	}

	/** hops we recognise are skipped; the first stranger is the caller */
	public function trustedHopsAreWalkedThrough(): bool
	{
		return $this->ip(['REMOTE_ADDR' => '10.0.0.5',
			'HTTP_X_FORWARDED_FOR' => '198.51.100.7, 10.0.0.9'], ['10.0.0.0/8'])
			=== '198.51.100.7';
	}

	/** what proxies really emit: ports, and bracketed IPv6 literals */
	public function portsAndBracketsAreStripped(): bool
	{
		$six = $this->ip(['REMOTE_ADDR' => '10.0.0.5',
			'HTTP_X_FORWARDED_FOR' => '[2001:db8::1]:443'], ['10.0.0.0/8']);
		$four = $this->ip(['REMOTE_ADDR' => '10.0.0.5',
			'HTTP_X_FORWARDED_FOR' => '198.51.100.7:52631'], ['10.0.0.0/8']);

		return $six === '2001:db8::1' && $four === '198.51.100.7';
	}

	/**
	 * A hop that is not an address ends the walk rather than being skipped —
	 * otherwise "unknown" becomes a way to hide the hops behind it
	 */
	public function malformedHopStopsTheWalk(): bool
	{
		return $this->ip(['REMOTE_ADDR' => '10.0.0.5',
			'HTTP_X_FORWARDED_FOR' => '198.51.100.7, unknown'], ['10.0.0.0/8'])
			=== '10.0.0.5';
	}

	/**
	 * Bitwise prefix matching, not string prefix: the old regex tested
	 * "172.16." literally and so missed 172.17 through 172.31 — most of the
	 * range it was written for
	 */
	public function cidrMatchingCoversTheWholeRange(): bool
	{
		$inside = $this->ip(['REMOTE_ADDR' => '172.20.0.4',
			'HTTP_X_FORWARDED_FOR' => '198.51.100.7'], ['172.16.0.0/12']);
		$outside = $this->ip(['REMOTE_ADDR' => '172.32.0.4',
			'HTTP_X_FORWARDED_FOR' => '198.51.100.7'], ['172.16.0.0/12']);

		return $inside === '198.51.100.7' && $outside === '172.32.0.4';
	}

	/** a plain address is a valid entry too */
	public function exactAddressIsAValidProxyEntry(): bool
	{
		return $this->ip(['REMOTE_ADDR' => '203.0.113.9',
			'HTTP_X_FORWARDED_FOR' => '198.51.100.7'], ['203.0.113.9'])
			=== '198.51.100.7';
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
	 * @param string[] $proxies
	 */
	protected function ip(
		array $server,
		array $proxies,
	): string
	{
		Subject::reset();
		Subject::setTrustedProxies($proxies);

		$_SERVER['REMOTE_ADDR'] = '';
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '';
		$_SERVER['HTTP_CLIENT_IP'] = '';

		foreach($server as $key => $value)
		{
			$_SERVER[$key] = $value;
		}

		$ip = Subject::getIp();
		Subject::reset();

		return $ip;
	}
}
