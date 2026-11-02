<?php
declare(strict_types=1);

namespace Tests\Stream;

use Ovos\Test;
use Ovos\Stream\Request as HttpRequest;
use Throwable;
use RuntimeException;

use function fopen;
use function fwrite;
use function rewind;
use function stream_wrapper_register;
use function stream_wrapper_unregister;
use function stripos;

/**
 * Request
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Request extends Test
{
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
	
	public function jsonContentPreservesExistingHeaders(): bool
	{
		$request = (new HttpRequest('http://localhost', ['http' => [
			'header' => ['Authorization: Basic abc123'],
		]]))->setJsonContent(['text' => 'hi']);
		$options = $request->buildContextOptions();
		
		return $this->headerHas($options, 'Authorization: Basic abc123')
			&& $this->headerHas($options, 'Content-Type: application/json');
	}
	
	public function jsonContentHandlesStringHeaderOption(): bool
	{
		$request = (new HttpRequest('http://localhost', ['http' => [
			'header' => 'Authorization: Basic abc123',
		]]))->setJsonContent(['text' => 'hi']);
		$options = $request->buildContextOptions();
		
		return $this->headerHas($options, 'Authorization: Basic abc123')
			&& $this->headerHas($options, 'Content-Type: application/json');
	}
	
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
			->setUserAgent('ovos-codesafe-uptime/1.0')
			->getContextOptions();
		
		return ($options['http']['user_agent'] ?? null) === 'ovos-codesafe-uptime/1.0';
	}
	
	public function statusCodeAndOkFromMetaData(): bool
	{
		$ok = $this->withWrapperData(['HTTP/1.1 200 OK']);
		$notFound = $this->withWrapperData(['HTTP/1.1 404 Not Found']);
		
		return $ok->getResponseStatusCode() === 200
			&& $ok->isOk() === true
			&& $notFound->getResponseStatusCode() === 404
			&& $notFound->isOk() === false;
	}
	
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
	
	public function okRequiresResponse(): bool
	{
		$request = new HttpRequest('http://localhost');
		
		return $request->getResponseStatusCode() === null
			&& $request->isOk() === false;
	}
	
	public function bestEffortSwallowsTransportFailure(): bool
	{
		$request = (new HttpRequest('http://127.0.0.1:1'))
			->setTimeout(1.0)
			->setBestEffort();
		$request->invoke();
		
		return $request->getResponseStatusCode() === null
			&& $request->isOk() === false;
	}
	
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
	
	public function formContentReplacesJson(): bool
	{
		$request = (new HttpRequest('http://localhost'))
			->setJsonContent(['old' => 1])
			->setContent(['new' => 2]);
		$options = $request->buildContextOptions();
		
		return $options['http']['content'] === 'new=2'
			&& $options['http']['header'] === ['Content-Type: application/x-www-form-urlencoded'];
	}
	
	public function jsonContentReplacesItsHeader(): bool
	{
		$request = (new HttpRequest('http://localhost', ['http' => [
			'header' => "Authorization: Basic abc123\r\ncontent-type: text/plain",
		]]))
			->setJsonContent(['old' => 1])
			->setJsonContent(['new' => 2]);
		$options = $request->buildContextOptions();
		
		return $options['http']['content'] === '{"new":2}'
			&& $options['http']['header'] === [
				'Authorization: Basic abc123',
				'Content-Type: application/json; charset=UTF-8',
			];
	}
	
	public function contextContentReplacesTheCurrentBody(): bool
	{
		$request = (new HttpRequest('http://localhost'))
			->setJsonContent(['old' => 1])
			->setContextOptions(['http' => [
				'content' => 'new body',
				'header' => ['Content-Type: text/plain'],
			]]);
		$options = $request->getContextOptions();
		$request->setContent(null);
		$cleared = $request->buildContextOptions();
		
		return $options['http']['content'] === 'new body'
			&& $request->getContent() === null
			&& $options['http']['header'] === ['Content-Type: text/plain']
			&& isset($cleared['http']['content']) === false;
	}
	
	public function formContentReplacesContextContent(): bool
	{
		$request = (new HttpRequest('http://localhost', ['http' => [
			'method' => 'PUT',
			'content' => 'old body',
			'header' => ['Content-Type: text/plain'],
		]]))->setContent(['new' => 2]);
		$options = $request->buildContextOptions();
		
		return $options['http']['method'] === 'PUT'
			&& $options['http']['content'] === 'new=2'
			&& $options['http']['header'] === ['Content-Type: application/x-www-form-urlencoded'];
	}
	
	public function bestEffortClosesStreamAfterReadFailure(): bool
	{
		return $this->readFailure(true);
	}
	
	public function strictReadFailureClosesStreamAndThrows(): bool
	{
		return $this->readFailure(false);
	}
	
	protected function readFailure(
		bool $bestEffort,
	): bool
	{
		ReadFailureStream::$closed = false;
		stream_wrapper_register('ovosreadfailure', ReadFailureStream::class);
		$request = (new HttpRequest('ovosreadfailure://response'))->setBestEffort($bestEffort);
		$thrown = false;
		try
		{
			try
			{
				$request->invoke();
			}
			catch(RuntimeException $exception)
			{
				$thrown = $exception->getMessage() === 'Read failed';
			}
			
			return $thrown === ($bestEffort === false)
				&& ReadFailureStream::$closed
				&& $request->getResponse() === null
				&& $request->getResponseMetaData() === null
				&& $request->getMeasurement()->getTotalTime() !== null;
		}
		finally
		{
			stream_wrapper_unregister('ovosreadfailure');
		}
	}
	
	public function failedReuseClearsThePreviousResponse(): bool
	{
		$request = new class('http://localhost') extends HttpRequest
		{
			public bool $fail = false;
			
			protected function openStream(
				string $url,
				mixed $context,
			): mixed
			{
				if($this->fail)
				{
					throw new RuntimeException('Open failed');
				}
				
				$stream = fopen('php://memory', 'r+');
				fwrite($stream, 'response');
				rewind($stream);
				
				return $stream;
			}
		};
		$request->invoke();
		$first = $request->getResponse();
		$request->fail = true;
		$request->setBestEffort()->invoke();
		
		return $first === 'response'
			&& $request->getResponse() === null
			&& $request->getResponseMetaData() === null
			&& $request->getMeasurement()->getTotalTime() !== null;
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

class ReadFailureStream
{
	public mixed $context;
	
	public static bool $closed = false;
	
	public function stream_open(
		string $path,
		string $mode,
		int $options,
		?string &$openedPath,
	): bool
	{
		return true;
	}
	
	public function stream_stat(): array
	{
		return [];
	}
	
	public function stream_eof(): bool
	{
		return false;
	}
	
	public function stream_read(
		int $count,
	): string
	{
		throw new RuntimeException('Read failed');
	}
	
	public function stream_close(): void
	{
		self::$closed = true;
	}
}
