<?php
declare(strict_types=1);

namespace Ovos\Stream;

use Ovos\Arrays;
use Ovos\Measurement;
use RuntimeException;
use Throwable;

use function array_key_exists;
use function array_values;
use function ceil;
use function explode;
use function fclose;
use function fopen;
use function http_build_query;
use function ini_set;
use function is_array;
use function is_numeric;
use function is_resource;
use function is_string;
use function json_decode;
use function json_encode;
use function preg_split;
use function str_contains;
use function str_starts_with;
use function stream_context_create;
use function stream_get_contents;
use function stream_get_meta_data;
use function strtolower;

use const JSON_THROW_ON_ERROR;
use const PREG_SPLIT_NO_EMPTY;

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
	
	protected array|string|null $content = null;
	
	protected ?string $contentType = null;
	
	protected ?string $response = null;
	
	/**
	 * @see https://www.php.net/manual/en/function.stream-get-meta-data.php
	 * @see https://www.php.net/manual/en/wrappers.http.php
	 */
	protected ?array $responseMetaData = null;
	
	protected Measurement $measurement;
	
	protected bool $bestEffort = false;
	
	/**
	 * Maximum response size in bytes; null reads to EOF.
	 */
	protected ?int $responseLimit = null;
	
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
		$this->response = null;
		$this->responseMetaData = null;
		$this->measurement = new Measurement;
		$this->getMeasurement()->start();
		
		try
		{
			$context ??= $this->createContext();
			$url = $this->url;
			$content = $this->getContent();
			if($this->getMethod() === self::METHOD_GET && is_array($content) && $content !== [])
			{
				$url.= (str_contains($url, '?') ? '&' : '?') . http_build_query($content);
			}
			
			try
			{
				if($this->bestEffort)
				{
					@$this->readResponse($url, $context);
				}
				else
				{
					$this->readResponse($url, $context);
				}
			}
			catch(Throwable $exception)
			{
				if($this->bestEffort === false)
				{
					throw $exception;
				}
			}
		}
		finally
		{
			$this->getMeasurement()->stop();
		}
		
		return $this;
	}
	
	protected function readResponse(
		string $url,
		mixed $context,
	): void
	{
		$stream = $this->openStream($url, $context);
		if($stream === false)
		{
			throw new RuntimeException('Stream\Request: failed to open ' . $url);
		}
		
		try
		{
			$response = stream_get_contents($stream, $this->responseLimit ?? -1);
			$metadata = stream_get_meta_data($stream);
			if($response === false || ($metadata['timed_out'] ?? false))
			{
				throw new RuntimeException('Stream\Request: failed to read ' . $url);
			}
		}
		finally
		{
			if(is_resource($stream))
			{
				fclose($stream);
			}
		}
		
		$this->response = $response;
		$this->responseMetaData = $metadata;
	}
	
	/**
	 * Apply the configured socket timeout while opening the stream.
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
			return fopen($url, 'r', false, $context);
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
	 * Socket timeout rounded up to whole seconds; null leaves the default.
	 */
	protected function socketTimeout(): ?int
	{
		$timeout = $this->contextOptions['http']['timeout'] ?? null;
		
		return is_numeric($timeout) ? (int)ceil((float)$timeout) : null;
	}
	
	/**
	 * Final HTTP status after redirects, or null when no response was read.
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
	 * Whether the response has a 2xx status.
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
	 * Encode the body and apply its content type to the stream options.
	 */
	public function buildContextOptions(): array
	{
		$contextOptions = $this->contextOptions;
		
		if($this->content !== null
			&& (is_array($this->content) === false || $this->getMethod() !== self::METHOD_GET))
		{
			$contextOptions['http']['content'] = is_array($this->content)
				? http_build_query($this->content)
				: $this->content;
			
			$headers = self::headerLines($contextOptions['http']['header'] ?? []);
			if($this->contentType !== null)
			{
				foreach($headers as $index => $header)
				{
					if(str_starts_with(strtolower($header), 'content-type:'))
					{
						unset($headers[$index]);
					}
				}
				$headers[] = 'Content-Type: ' . $this->contentType;
			}
			elseif($this->getMethod() === self::METHOD_POST
				&& $this->hasHeader($contextOptions, 'Content-Type') === false)
			{
				$headers[] = 'Content-Type: application/x-www-form-urlencoded';
			}
			$contextOptions['http']['header'] = array_values($headers);
		}
		
		return $contextOptions;
	}
	
	/**
	 * Match header names without case sensitivity.
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
	 * Accept the array and string forms of the http.header option.
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
		if(array_key_exists('content', $contextOptions['http'] ?? []))
		{
			$this->setContent($contextOptions['http']['content']);
			unset($contextOptions['http']['content']);
		}
		
		$this->contextOptions = Arrays::deepMerge(
			$this->contextOptions,
			$contextOptions,
		);
		
		return $this;
	}
	
	public function getContextOptions(): array
	{
		return $this->buildContextOptions();
	}
	
	/**
	 * Read timeout in seconds, also used for the socket timeout when connecting.
	 */
	public function setTimeout(
		float $seconds,
	): static
	{
		$this->setContextOptions(['http' => ['timeout' => $seconds]]);
		
		return $this;
	}
	
	/**
	 * Read the response body and status for HTTP errors too.
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
	 * Append a request header.
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
	 * Set a JSON body and use POST. setMethod() can override the method.
	 */
	public function setJsonContent(
		mixed $data,
	): static
	{
		return $this->setContent(
			json_encode($data, flags: JSON_THROW_ON_ERROR),
			'application/json; charset=UTF-8',
		)->setMethod(self::METHOD_POST);
	}
	
	/**
	 * Leave a null response on transport failures instead of throwing.
	 */
	public function setBestEffort(
		bool $bestEffort = true,
	): static
	{
		$this->bestEffort = $bestEffort;
		
		return $this;
	}
	
	/**
	 * Maximum response size in bytes; null reads to EOF.
	 */
	public function setResponseLimit(
		?int $bytes,
	): static
	{
		$this->responseLimit = $bytes;
		
		return $this;
	}
	
	/**
	 * Arrays become GET parameters or a form body; strings are sent as given.
	 */
	public function setContent(
		array|string|null $content,
		?string $contentType = null,
	): static
	{
		$this->content = $content;
		$this->contentType = $contentType ?? (is_array($content)
			? 'application/x-www-form-urlencoded'
			: null);
		
		return $this;
	}
	
	public function getContent(): array|string|null
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
