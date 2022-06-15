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
	public const METHOD_GET = 'GET';
	public const METHOD_POST = 'POST';
	public const METHOD_PUT = 'PUT';
	/**#@-*/
	
	/**
	 * @var string
	 */
	protected string $_url;

	/**
	 * @var array
	 */
	protected array $_context = [
		'http' => [
			'method' => self::METHOD_GET,
		]
	];

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
		array $context = [],
	)
	{
		$this->_url = $url;
		$this->setContext($context);
		
		$this->_measurement = new Measurement;
	}
	
	/**
	 * @param mixed $context
	 *
	 * @return self
	 */
	public function invoke(mixed $context = null): self
	{
		$this->getMeasurement()->start();
		
		if($context === null)
		{
			$context = stream_context_create($this->_context);
		}
		
		$stream = fopen($this->_url, 'r', false, $context);
		$this->_responseMetaData = stream_get_meta_data($stream);
		$this->_response = stream_get_contents($stream);
		
		$this->getMeasurement()->stop();
		
		return $this;
	}
	
	/**
	 * @return mixed
	 */
	public function getJsonResponse(): mixed
	{
		return json_decode($this->_response, flags: JSON_THROW_ON_ERROR);
	}

	/**
	 * @param string $method
	 *
	 * @return self
	 */
	public function setMethod(string $method): self
	{
		$this->_context['http']['method'] = $method;

		return $this;
	}

	/**
	 * @return string
	 */
	public function getMethod(): ?string
	{
		return $this->_context['http']['method'];
	}
	
	/**
	 * @return string
	 */
	public function getUrl(): string
	{
		return $this->_url;
	}

	/**
	 * @param array $context
	 *
	 * @return self
	 */
	public function setContext(array $context): self
	{
		$this->_context = Arrays::deepMerge($this->_context, $context);

		return $this;
	}

	/**
	 * @return array
	 */
	public function getContext(): array
	{
		return $this->_context;
	}
	
	/**
	 * @return ?string
	 */
	public function getResponse(): ?string
	{
		return $this->_response;
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
