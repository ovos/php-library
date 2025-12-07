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
	protected string $baseUrl;
	
	protected array $defaultContextOptions = [];
	
	/**
	 */
	public function __construct(
		string $baseUrl,
		array $defaultContextOptions = [],
	)
	{
		$this->baseUrl = $baseUrl;
		$this->defaultContextOptions = $defaultContextOptions;
	}
	
	public function create(
		string $url = '',
		array $contextOptions = [],
	): Request
	{
		$requestUrl = $this->baseUrl . $url;
		$requestContextOptions = Arrays::deepMerge($this->defaultContextOptions,
			$contextOptions,
		);
		return new Request($requestUrl, $requestContextOptions);
	}
	
	public function setBaseUrl(
		string $baseUrl,
	): static
	{
		$this->baseUrl = $baseUrl;
		
		return $this;
	}
	
	public function getBaseUrl(): string
	{
		return $this->baseUrl;
	}
	
	public function setDefaultContextOptions(
		array $defaultContextOptions,
	): static
	{
		$this->defaultContextOptions = $defaultContextOptions;
		
		return $this;
	}
	
	public function getDefaultContextOptions(): array
	{
		return $this->defaultContextOptions;
	}
}
