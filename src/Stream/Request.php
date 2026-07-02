<?php
declare(strict_types=1);

namespace Ovos\Stream;

use Ovos\Arrays;
use Ovos\Measurement;
use RuntimeException;
use Throwable;

/**
 * Request
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Request
{
	// Methods
	public const string METHOD_GET = 'GET';
	public const string METHOD_POST = 'POST';
	public const string METHOD_PUT = 'PUT';
	
	/**
	 * Global URL, for cases when the project uses only a single API,
	 * thus Ovos\Stream\Request\Factory is not needed
	 */
	protected string $url;
	
	/**
	 * Global context for all requests
	 */
	protected array $contextOptions = [
		'http' => [
			'method' => self::METHOD_GET,
			'header' => [], // required by createContext()
		]
	];
	
	protected ?array $content = null;
	
	protected ?string $response = null;
	
	/**
	 * @see https://www.php.net/manual/en/function.stream-get-meta-data.php
	 * @see https://www.php.net/manual/en/wrappers.http.php
	 */
	protected ?array $responseMetaData = null;
	
	protected Measurement $measurement;
	
	/**
	 * Best-effort mode — see setBestEffort().
	 */
	protected bool $bestEffort = false;
	
	/**
	 */
	public function __construct(
		string $url,
		array $contextOptions = [],
	)
	{
		$this->url = $url;
		$this->setContextOptions($contextOptions);
		
		$this->measurement = new Measurement;
	}
	
	public function invoke(
		mixed $context = null,
	): static
	{
		$this->getMeasurement()->start();
		
		if($context === null)
		{
			$context = $this->createContext();
		}
		
		$url = $this->url;
		if($this->getMethod() === self::METHOD_GET
			&& $content = $this->getContent())
		{
			// the URL may already carry a query string (health probes often
			// do) — appending a second '?' would mangle both parameter sets
			$url.= (str_contains($url, '?') ? '&' : '?')
				. http_build_query($content);
		}
		
		$stream = $this->openStream($url, $context);
		
		if($stream === false)
		{
			// best-effort degrades a transport failure (DNS, refused, TLS,
			// timeout) to a null response; with it off a failure must surface —
			// fopen already warns, but should that warning not be promoted to an
			// exception, raise one so the failure is never silently swallowed
			if($this->bestEffort === false)
			{
				$this->getMeasurement()->stop();
				
				throw new RuntimeException('Stream\Request: failed to open ' . $this->url);
			}
			
			$this->response = null;
			$this->responseMetaData = null;
			$this->getMeasurement()->stop();
			
			return $this;
		}
		
		$this->responseMetaData = stream_get_meta_data($stream);
		$this->response = stream_get_contents($stream);
		fclose($stream);
		
		$this->getMeasurement()->stop();
		
		return $this;
	}
	
	/**
	 * Open the request stream. The connection phase is bounded by a scoped
	 * default_socket_timeout when a timeout is set (the http wrapper has no
	 * separate connect-timeout knob); in best-effort mode a transport failure
	 * is swallowed and returned as false so invoke() can degrade to a null
	 * response, otherwise the fopen warning/exception surfaces as before.
	 *
	 * @return resource|false
	 */
	protected function openStream(
		string $url,
		mixed $context,
	): mixed
	{
		$socketTimeout = $this->socketTimeout();
		$restoreSocketTimeout = $socketTimeout !== null
			? ini_set('default_socket_timeout', (string)$socketTimeout)
			: false;
		
		try
		{
			if($this->bestEffort === false)
			{
				return fopen($url, 'r', false, $context);
			}
			
			try
			{
				return @fopen($url, 'r', false, $context);
			}
			catch(Throwable)
			{
				return false;
			}
		}
		finally
		{
			if($restoreSocketTimeout !== false)
			{
				ini_set('default_socket_timeout', $restoreSocketTimeout);
			}
		}
	}
	
	/**
	 * The connection-phase bound in whole seconds, derived from the effective
	 * http.timeout so the read and connect limits never diverge (the http
	 * wrapper has no separate connect-timeout knob the way curl does). Null when
	 * no timeout is configured.
	 */
	protected function socketTimeout(): ?int
	{
		$timeout = $this->contextOptions['http']['timeout'] ?? null;
		
		return is_numeric($timeout) ? (int)ceil((float)$timeout) : null;
	}
	
	/**
	 * The final HTTP status code from the response meta data. After following
	 * redirects wrapper_data carries one status line per hop, so the last one
	 * wins. Null when there is no response (e.g. a transport failure).
	 *
	 * 'wrapper_data' =>
	 *   [0] =>
	 *   string(15) "HTTP/1.1 200 OK"
	 */
	public function getResponseStatusCode(): ?int
	{
		if($this->responseMetaData === null)
		{
			return null;
		}
		
		if(isset($this->responseMetaData['wrapper_data']) === false)
		{
			return null;
		}
		
		$code = null;
		foreach($this->responseMetaData['wrapper_data'] as $header)
		{
			if(str_starts_with($header, 'HTTP/') === false)
			{
				continue;
			}
			
			$parts = explode(' ', $header);
			$code = (int)$parts[1];
		}
		
		return $code;
	}
	
	/**
	 * Whether the response carried a 2xx status. A transport failure leaves the
	 * status null, so this returns false — the best-effort success test.
	 */
	public function isOk(): bool
	{
		$code = $this->getResponseStatusCode();
		
		return $code !== null && $code >= 200 && $code < 300;
	}
	
	/**
	 * @return ?resource
	 */
	public function createContext(
	): mixed
	{
		return stream_context_create($this->buildContextOptions());
	}
	
	/**
	 * Resolve the final stream context options. Split out from createContext()
	 * so the request body / header handling stays unit-testable without
	 * opening a socket.
	 */
	public function buildContextOptions(): array
	{
		$contextOptions = $this->contextOptions;
		
		if($this->contextOptions['http']['method'] === self::METHOD_POST)
		{
			if($this->content !== null
				&& isset($contextOptions['http']['content']) === false)
			{
				$contextOptions['http']['content']
					= http_build_query($this->content);
			}
			
			if(isset($contextOptions['http']['content'])
				&& $this->hasHeader($contextOptions, 'Content-Type') === false)
			{
				$headers = self::headerLines($contextOptions['http']['header'] ?? []);
				$headers[] = 'Content-Type: application/x-www-form-urlencoded';
				$contextOptions['http']['header'] = $headers;
			}
		}
		
		return $contextOptions;
	}
	
	/**
	 * Whether a header line is already present (case-insensitive name match),
	 * so we never append a second, conflicting Content-Type.
	 */
	protected function hasHeader(
		array $contextOptions,
		string $name,
	): bool
	{
		$needle = strtolower($name) . ':';
		foreach(self::headerLines($contextOptions['http']['header'] ?? []) as $header)
		{
			if(str_starts_with(strtolower($header), $needle))
			{
				return true;
			}
		}
		
		return false;
	}
	
	/**
	 * Normalize the http.header option to a line list. Streams also accept a
	 * single CRLF-joined string, so coerce that to an array — otherwise an
	 * append (addHeader, the urlencoded default) would fatal on a string.
	 */
	protected static function headerLines(
		mixed $header,
	): array
	{
		if(is_array($header))
		{
			return $header;
		}
		
		if(is_string($header) === false || $header === '')
		{
			return [];
		}
		
		$lines = preg_split('~\r\n|\r|\n~', $header, -1, PREG_SPLIT_NO_EMPTY);
		
		return $lines === false ? [] : $lines;
	}
	
	public function setMethod(
		string $method,
	): static
	{
		$this->contextOptions['http']['method'] = $method;
		
		return $this;
	}
	
	public function getMethod(): ?string
	{
		return $this->contextOptions['http']['method'];
	}
	
	public function setUrl(
		string $url,
	): static
	{
		$this->url = $url;
		
		return $this;
	}
	
	public function getUrl(): string
	{
		return $this->url;
	}
	
	public function setContextOptions(
		array $contextOptions,
	): static
	{
		$this->contextOptions = Arrays::deepMerge(
			$this->contextOptions,
			$contextOptions,
		);
		
		return $this;
	}
	
	public function getContextOptions(): array
	{
		return $this->contextOptions;
	}
	
	/**
	 * Overall timeout in seconds, stored as http.timeout — the single source of
	 * truth, so the read and the connection bound (socketTimeout()) never
	 * diverge even if http.timeout is later changed via setContextOptions().
	 */
	public function setTimeout(
		float $seconds,
	): static
	{
		$this->setContextOptions(['http' => ['timeout' => $seconds]]);
		
		return $this;
	}
	
	/**
	 * Keep the response (and its status) on a 4xx/5xx instead of letting fopen
	 * fail — required when the caller wants to read error statuses (e.g. probes).
	 */
	public function setIgnoreErrors(
		bool $ignoreErrors = true,
	): static
	{
		$this->setContextOptions(['http' => ['ignore_errors' => $ignoreErrors]]);
		
		return $this;
	}
	
	/**
	 * Follow up to $maxRedirects redirect hops. Streams count the initial
	 * request in max_redirects, so allow one more than the hops we want.
	 */
	public function setFollowRedirects(
		int $maxRedirects,
	): static
	{
		$this->setContextOptions(['http' => [
			'follow_location' => $maxRedirects > 0 ? 1 : 0,
			'max_redirects' => $maxRedirects + 1,
		]]);
		
		return $this;
	}
	
	public function setUserAgent(
		string $userAgent,
	): static
	{
		$this->setContextOptions(['http' => ['user_agent' => $userAgent]]);
		
		return $this;
	}
	
	/**
	 * Append a request header. Unlike passing a header array through
	 * setContextOptions() — whose deep-merge collides on numeric keys and would
	 * overwrite an existing header — this always adds to the list.
	 */
	public function addHeader(
		string $header,
	): static
	{
		$headers = self::headerLines($this->contextOptions['http']['header'] ?? []);
		$headers[] = $header;
		$this->contextOptions['http']['header'] = $headers;
		
		return $this;
	}
	
	/**
	 * Send $data as a JSON request body (implies POST). Appends the JSON
	 * Content-Type (keeping any existing headers), so buildContextOptions()
	 * won't add the urlencoded one.
	 */
	public function setJsonContent(
		mixed $data,
	): static
	{
		$this->setMethod(self::METHOD_POST);
		$this->setContextOptions(['http' => [
			'content' => json_encode($data, flags: JSON_THROW_ON_ERROR),
		]]);
		$this->addHeader('Content-Type: application/json; charset=UTF-8');
		
		return $this;
	}
	
	/**
	 * Best-effort mode: a transport failure (DNS, connection refused, TLS,
	 * timeout) degrades to a null response instead of surfacing the fopen
	 * warning/exception — for probes and fire-and-forget posts that must not
	 * abort the caller. Off by default, so existing callers still see failures.
	 */
	public function setBestEffort(
		bool $bestEffort = true,
	): static
	{
		$this->bestEffort = $bestEffort;
		
		return $this;
	}
	
	public function setContent(
		?array $content,
	): static
	{
		$this->content = $content;
		
		return $this;
	}
	
	public function getContent(): ?array
	{
		return $this->content;
	}
	
	public function getResponse(): ?string
	{
		return $this->response;
	}
	
	public function getJsonResponse(): mixed
	{
		return json_decode($this->response, flags: JSON_THROW_ON_ERROR);
	}
	
	/**
	 * @see https://www.php.net/manual/en/function.stream-get-meta-data.php
	 * @see https://www.php.net/manual/en/wrappers.http.php
	 */
	public function getResponseMetaData(): ?array
	{
		return $this->responseMetaData;
	}
	
	public function getMeasurement(): Measurement
	{
		return $this->measurement;
	}
}
