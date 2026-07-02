<?php
declare(strict_types=1);

namespace Tests\Stream;

use Ovos\Test;
use Ovos\Stream\Request as HttpRequest;
use Throwable;

use function stripos;

/**
 * Request
 *
 * Network-free coverage of the probe-oriented helpers: context-option building
 * (JSON vs form body, timeout, ignore-errors, redirects, user-agent) and status
 * parsing. No socket is opened — buildContextOptions() and a faked wrapper_data
 * keep these deterministic.
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Request extends Test
{
	/**
	 * A JSON body implies POST, carries the encoded payload, sets the JSON
	 * Content-Type and does NOT add the form-urlencoded one.
	 */
	public function jsonContentImpliesPostAndBody(): bool
	{
		$request = (new HttpRequest('http://localhost'))
			->setJsonContent(['text' => 'hi']);
		$options = $request->buildContextOptions();
		
		return $request->getMethod() === HttpRequest::METHOD_POST
			&& ($options['http']['content'] ?? null) === '{"text":"hi"}'
			&& $this->headerHas($options, 'Content-Type: application/json')
			&& $this->headerHas($options, 'application/x-www-form-urlencoded') === false;
	}
	
	/**
	 * setJsonContent must append its Content-Type, not clobber existing headers
	 * (e.g. an Authorization carried in from the constructor / factory context).
	 */
	public function jsonContentPreservesExistingHeaders(): bool
	{
		$request = (new HttpRequest('http://localhost', ['http' => [
			'header' => ['Authorization: Basic abc123'],
		]]))->setJsonContent(['text' => 'hi']);
		$options = $request->buildContextOptions();
		
		return $this->headerHas($options, 'Authorization: Basic abc123')
			&& $this->headerHas($options, 'Content-Type: application/json');
	}
	
	/**
	 * http.header may arrive as a CRLF-joined string (a valid stream-context
	 * form). addHeader / setJsonContent must coerce it to a list and append,
	 * not fatal trying to push onto a string.
	 */
	public function jsonContentHandlesStringHeaderOption(): bool
	{
		$request = (new HttpRequest('http://localhost', ['http' => [
			'header' => 'Authorization: Basic abc123',
		]]))->setJsonContent(['text' => 'hi']);
		$options = $request->buildContextOptions();
		
		return $this->headerHas($options, 'Authorization: Basic abc123')
			&& $this->headerHas($options, 'Content-Type: application/json');
	}
	
	/**
	 * The legacy array-content POST path still defaults to form-urlencoded.
	 */
	public function formContentDefaultsToUrlencoded(): bool
	{
		$request = (new HttpRequest('http://localhost'))
			->setMethod(HttpRequest::METHOD_POST)
			->setContent(['a' => 'b']);
		$options = $request->buildContextOptions();
		
		return ($options['http']['content'] ?? null) === 'a=b'
			&& $this->headerHas($options, 'application/x-www-form-urlencoded');
	}
	
	public function timeoutSetsContextOption(): bool
	{
		$options = (new HttpRequest('http://localhost'))
			->setTimeout(2.5)
			->getContextOptions();
		
		return ($options['http']['timeout'] ?? null) === 2.5;
	}
	
	/**
	 * The connection bound follows the effective http.timeout even after a later
	 * setContextOptions() overrides it, so connect and read can't diverge.
	 */
	public function connectTimeoutTracksHttpTimeout(): bool
	{
		$request = new class('http://localhost') extends HttpRequest
		{
			public function exposedSocketTimeout(): ?int
			{
				return $this->socketTimeout();
			}
		};
		$request->setTimeout(5.0);
		$first = $request->exposedSocketTimeout();
		$request->setContextOptions(['http' => ['timeout' => 2]]);
		$second = $request->exposedSocketTimeout();
		
		return $first === 5 && $second === 2;
	}
	
	public function ignoreErrorsSetsContextOption(): bool
	{
		$on = (new HttpRequest('http://localhost'))
			->setIgnoreErrors()
			->getContextOptions();
		$off = (new HttpRequest('http://localhost'))
			->setIgnoreErrors(false)
			->getContextOptions();
		
		return ($on['http']['ignore_errors'] ?? null) === true
			&& ($off['http']['ignore_errors'] ?? null) === false;
	}
	
	/**
	 * Streams count the initial request in max_redirects, so N hops maps to
	 * max_redirects = N + 1; zero hops disables following.
	 */
	public function followRedirectsMapsToMaxRedirects(): bool
	{
		$follow = (new HttpRequest('http://localhost'))
			->setFollowRedirects(3)
			->getContextOptions();
		$none = (new HttpRequest('http://localhost'))
			->setFollowRedirects(0)
			->getContextOptions();
		
		return ($follow['http']['follow_location'] ?? null) === 1
			&& ($follow['http']['max_redirects'] ?? null) === 4
			&& ($none['http']['follow_location'] ?? null) === 0
			&& ($none['http']['max_redirects'] ?? null) === 1;
	}
	
	public function userAgentSetsContextOption(): bool
	{
		$options = (new HttpRequest('http://localhost'))
			->setUserAgent('ovos-console-uptime/1.0')
			->getContextOptions();
		
		return ($options['http']['user_agent'] ?? null) === 'ovos-console-uptime/1.0';
	}
	
	/**
	 * Status is parsed from wrapper_data, and isOk() is exactly the 2xx range.
	 */
	public function statusCodeAndOkFromMetaData(): bool
	{
		$ok = $this->withWrapperData(['HTTP/1.1 200 OK']);
		$notFound = $this->withWrapperData(['HTTP/1.1 404 Not Found']);
		
		return $ok->getResponseStatusCode() === 200
			&& $ok->isOk() === true
			&& $notFound->getResponseStatusCode() === 404
			&& $notFound->isOk() === false;
	}
	
	/**
	 * After following redirects wrapper_data holds a status line per hop; the
	 * final response status wins, not the first.
	 */
	public function statusCodeIsFinalAfterRedirects(): bool
	{
		$request = $this->withWrapperData([
			'HTTP/1.1 301 Moved Permanently',
			'Location: https://example.com/',
			'HTTP/1.1 200 OK',
		]);
		
		return $request->getResponseStatusCode() === 200
			&& $request->isOk() === true;
	}
	
	/**
	 * Without a response (e.g. a transport failure) the status is null and the
	 * best-effort success test is false.
	 */
	public function okRequiresResponse(): bool
	{
		$request = new HttpRequest('http://localhost');
		
		return $request->getResponseStatusCode() === null
			&& $request->isOk() === false;
	}
	
	/**
	 * Best-effort mode swallows a transport failure (here: connection refused
	 * on 127.0.0.1:1) and degrades to a null status instead of throwing — even
	 * under the application error handler that turns warnings into exceptions.
	 */
	public function bestEffortSwallowsTransportFailure(): bool
	{
		$request = (new HttpRequest('http://127.0.0.1:1'))
			->setTimeout(1.0)
			->setBestEffort();
		$request->invoke();
		
		return $request->getResponseStatusCode() === null
			&& $request->isOk() === false;
	}
	
	/**
	 * With best-effort off a transport failure must surface, not degrade to a
	 * null response: under the app error handler the fopen warning becomes an
	 * ErrorException, and without one invoke() raises its own — either way it
	 * throws rather than returning a silent null.
	 */
	public function nonBestEffortSurfacesTransportFailure(): bool
	{
		$request = (new HttpRequest('http://127.0.0.1:1'))->setTimeout(1.0);
		try
		{
			$request->invoke();
		}
		catch(Throwable)
		{
			return true;
		}
		
		return false;
	}
	
	/**
	 * GET content becomes the query string; a URL that already carries one
	 * gets '&', not a second '?' (which would mangle both parameter sets —
	 * health probes commonly carry ?token=… URLs).
	 */
	public function getContentAppendsQueryString(): bool
	{
		$plain = $this->capturingRequest('http://localhost/health');
		$plain->setBestEffort()->setContent(['probe' => 1]);
		$plain->invoke();
		
		$existing = $this->capturingRequest('http://localhost/health?token=abc');
		$existing->setBestEffort()->setContent(['probe' => 1]);
		$existing->invoke();
		
		return $plain->openedUrl === 'http://localhost/health?probe=1'
			&& $existing->openedUrl === 'http://localhost/health?token=abc&probe=1';
	}
	
	protected function headerHas(
		array $contextOptions,
		string $needle,
	): bool
	{
		foreach($contextOptions['http']['header'] ?? [] as $header)
		{
			if(stripos($header, $needle) !== false)
			{
				return true;
			}
		}
		
		return false;
	}
	
	/**
	 * A Request that records the final URL instead of opening a socket.
	 *
	 * @return HttpRequest&object{openedUrl: ?string}
	 */
	protected function capturingRequest(
		string $url,
	): HttpRequest
	{
		return new class($url) extends HttpRequest
		{
			public ?string $openedUrl = null;
			
			protected function openStream(
				string $url,
				mixed $context,
			): mixed
			{
				$this->openedUrl = $url;
				
				return false;
			}
		};
	}
	
	/**
	 * A Request with a faked wrapper_data, so status parsing can be exercised
	 * without opening a socket.
	 */
	protected function withWrapperData(
		array $wrapperData,
	): HttpRequest
	{
		$request = new class('http://localhost') extends HttpRequest
		{
			public function fakeResponse(
				array $wrapperData,
			): void
			{
				$this->responseMetaData = ['wrapper_data' => $wrapperData];
			}
		};
		$request->fakeResponse($wrapperData);
		
		return $request;
	}
}
