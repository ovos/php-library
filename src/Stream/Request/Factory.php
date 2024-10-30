<?php
declare(strict_types=1);

namespace Ovos\Stream\Request;

use Ovos\Arrays;
use Ovos\Stream\Request;

/**
 * Factory
 * Use this factory to group types of requests (URL & Context)
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
class Factory
{
	/**
	 * @var string
	 */
	protected string $_baseUrl;
	
	/**
	 * @var array
	 */
	protected array $_defaultContextOptions = [];
	
	/**
	 */
	public function __construct(string $baseUrl,
		array $defaultContextOptions = [],
	)
	{
		$this->_baseUrl = $baseUrl;
		$this->_defaultContextOptions = $defaultContextOptions;
	}
	
	/**
	 * @param string $url
	 * @param array $contextOptions
	 *
	 * @return Request
	 */
	public function create(string $url  = '', array $contextOptions = []): Request
	{
		$requestUrl = $this->_baseUrl . $url;
		$requestContextOptions = Arrays::deepMerge($this->_defaultContextOptions, $contextOptions);
		return new Request($requestUrl, $requestContextOptions);
	}
	
	/**
	 * @param string $baseUrl
	 *
	 * @return self
	 */
	public function setBaseUrl(string $baseUrl): self
	{
		$this->_baseUrl = $baseUrl;
		
		return $this;
	}
	
	/**
	 * @return string
	 */
	public function getBaseUrl(): string
	{
		return $this->_baseUrl;
	}
	
	/**
	 * @param array $defaultContextOptions
	 *
	 * @return self
	 */
	public function setDefaultContextOptions(array $defaultContextOptions): self
	{
		$this->_defaultContextOptions = $defaultContextOptions;
		
		return $this;
	}
	
	/**
	 * @return array
	 */
	public function getDefaultContextOptions(): array
	{
		return $this->_defaultContextOptions;
	}
}
