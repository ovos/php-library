<?php
declare(strict_types=1);

namespace Ovos\Stream;

use Ovos\Arrays;
use Ovos\Measurement;

/**
 * Request
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
class Request
{
	/**#@+
	 * Method constants
	 */
	public const string METHOD_GET = 'GET';
	public const string METHOD_POST = 'POST';
	public const string METHOD_PUT = 'PUT';
	/**#@-*/
	
	/**
	 * Global URL, for cases when the project uses only a single API, thus Ovos\Stream\Request\Factory is not needed
	 * 
	 * @var string
	 */
	protected string $_url;
	
	/**
	 * Global context for all requests
	 * 
	 * @var array
	 */
	protected array $_contextOptions = [
		'http' => [
			'method' => self::METHOD_GET,
			'header' => [], // required by createContext()
		]
	];
	
	/**
	 * @var ?array
	 */
	protected ?array $_content = null;
	
	/**
	 * @var ?string
	 */
	protected ?string $_response = null;
	
	/**
	 * @see https://www.php.net/manual/en/function.stream-get-meta-data.php
	 * @see https://www.php.net/manual/en/wrappers.http.php
	 * 
	 * @var ?array
	 */
	protected ?array $_responseMetaData = null;
	
	/**
	 * @var Measurement
	 */
	protected Measurement $_measurement;
	
	/**
	 */
	public function __construct(string $url,
		array $contextOptions = [],
	)
	{
		$this->_url = $url;
		$this->setContextOptions($contextOptions);
		
		$this->_measurement = new Measurement;
	}
	
	/**
	 * @param ?resource $context
	 *
	 * @return self
	 */
	public function invoke(mixed $context = null): self
	{
		$this->getMeasurement()->start();
		
		if($context === null)
		{
			$context = $this->createContext();
		}
		
		$url = $this->_url;
		if($this->getMethod() === self::METHOD_GET
			&& $content = $this->getContent())
		{
			$url.= '?' . http_build_query($content);
		}
		$stream = fopen($url, 'r', false, $context);
		$this->_responseMetaData = stream_get_meta_data($stream);
		$this->_response = stream_get_contents($stream);
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
 	 * 
	 * @return ?int
	 */
	public function getResponseStatusCode(): ?int
	{
		if($this->_responseMetaData === null)
		{
			return null;
		}
		
		if(!isset($this->_responseMetaData['wrapper_data']))
		{
			return null;
		}
		
		foreach($this->_responseMetaData['wrapper_data'] as $header)
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
		$contextOptions = $this->_contextOptions;
		
		if($this->_contextOptions['http']['method'] === self::METHOD_POST)
		{
			if($this->_content !== null && !isset($contextOptions['http']['content']))
			{
				$contextOptions['http']['content'] = http_build_query($this->_content);
			}
			
			if(isset($contextOptions['http']['content']))
			{
				$contextOptions['http']['header'][] = 'Content-Type: application/x-www-form-urlencoded';
			}
		}
		
		return stream_context_create($contextOptions);
	}	
	
	/**
	 * @param string $method
	 *
	 * @return self
	 */
	public function setMethod(string $method): self
	{
		$this->_contextOptions['http']['method'] = $method;
		
		return $this;
	}
	
	/**
	 * @return ?string
	 */
	public function getMethod(): ?string
	{
		return $this->_contextOptions['http']['method'];
	}
	
	/**
	 * @param string $url
	 *
	 * @return self
	 */
	public function setUrl(string $url): self
	{
		$this->_url = $url;
		
		return $this;
	}
	
	/**
	 * @return string
	 */
	public function getUrl(): string
	{
		return $this->_url;
	}
	
	/**
	 * @param array $contextOptions
	 *
	 * @return self
	 */
	public function setContextOptions(array $contextOptions): self
	{
		$this->_contextOptions = Arrays::deepMerge($this->_contextOptions, $contextOptions);
		
		return $this;
	}
	
	/**
	 * @return array
	 */
	public function getContextOptions(): array
	{
		return $this->_contextOptions;
	}
	
	/**
	 * @param ?array $content
	 *
	 * @return self
	 */
	public function setContent(?array $content): self
	{
		$this->_content = $content;
		
		return $this;
	}
	
	/**
	 * @return ?array
	 */
	public function getContent(): ?array
	{
		return $this->_content;
	}
	
	/**
	 * @return ?string
	 */
	public function getResponse(): ?string
	{
		return $this->_response;
	}
	
	/**
	 * @return mixed
	 */
	public function getJsonResponse(): mixed
	{
		return json_decode($this->_response, flags: JSON_THROW_ON_ERROR);
	}
	
	/**
	 * @see https://www.php.net/manual/en/function.stream-get-meta-data.php
	 * @see https://www.php.net/manual/en/wrappers.http.php
	 * 
	 * @return ?array
	 */
	public function getResponseMetaData(): ?array
	{
		return $this->_responseMetaData;
	}
	
	/**
	 * @return Measurement
	 */
	public function getMeasurement(): Measurement
	{
		return $this->_measurement;
	}
}
