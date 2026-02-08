<?php
declare(strict_types=1);

namespace Ovos\Stream;

use Ovos\Arrays;
use Ovos\Measurement;

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
			$url.= '?' . http_build_query($content);
		}
		$stream = fopen($url, 'r', false, $context);
		$this->responseMetaData = stream_get_meta_data($stream);
		$this->response = stream_get_contents($stream);
		fclose($stream);
		
		$this->getMeasurement()->stop();
		
		return $this;
	}
	
	/**
	 * Parse meta data and return status code
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
		
		foreach($this->responseMetaData['wrapper_data'] as $header)
		{
			if(str_starts_with($header, 'HTTP/') === false)
			{
				continue;
			}
			
			$parts = explode(' ', $header);
			return (int)$parts[1];
		}
		
		return null;
	}
	
	/**
	 * @return ?resource
	 */
	public function createContext(
	): mixed
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
			
			if(isset($contextOptions['http']['content']))
			{
				$contextOptions['http']['header'][]
					= 'Content-Type: application/x-www-form-urlencoded';
			}
		}
		
		return stream_context_create($contextOptions);
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
