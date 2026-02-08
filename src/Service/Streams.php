<?php
declare(strict_types=1);

namespace Ovos\Service;

use Ovos\Container\Inject;
use Ovos\Container\ArrayObject as InjectArrayObject;
use Ovos\Service;
use Ovos\ArrayObject;
use Ovos\Stream\Request;
use Ovos\Stream\Request\Factory;
use ErrorException;

/**
 * Streams
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Streams extends Service
{
	public const string SYMBOL = 'streams';
	
	public const int DEFAULT_TIMEOUT = 15;
	
	protected ?ArrayObject $config = null;
	
	protected array $defaultContextOptions = [];
	
	protected array $requests = [];
	
	public function __construct(
		#[Inject('config')]
		#[InjectArrayObject('streams')]
		ArrayObject $config,
	)
	{
		$this->config = $config;
		
		$this->defaultContextOptions = [
			'http' => [
				'timeout' => $this->config->timeout ?: self::DEFAULT_TIMEOUT,
			],
		];
		
		if($this->config->username && $this->config->password)
		{
			$auth = base64_encode(sprintf('%s:%s',
				$this->config->username,
				$this->config->password,
			));
			
			$this->defaultContextOptions['http']['header']
				= 'Authorization: Basic ' . $auth;
		}
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
	
	/**
	 * @throws ErrorException (from fopen in invoke())
	 */
	public function request(
		string $url,
		array $contextOptions = [],
	): mixed
	{
		$requestUrl = ($this->config->base_url ?? $this->config->url) . $url; // -> url for BC
		$request = (new Request($requestUrl, $this->defaultContextOptions))
			->setContextOptions($contextOptions);
		$object = $request->invoke()->getJsonResponse();
		$this->requests[] = $request;
		
		return $object;
	}
	
	/**
	 * Pass a request created with a factory() method
	 *
	 * @throws ErrorException (from fopen in invoke())
	 */
	public function invoke(
		Request $request,
	): mixed
	{
		$object = $request->invoke()->getJsonResponse();
		$this->requests[] = $request;
		
		return $object;
	}
	
	public function factory(
		string $baseUrl,
		array $defaultContextOptions = [],
	): Factory
	{
		return (new Factory($baseUrl, $this->defaultContextOptions))
			->setDefaultContextOptions($defaultContextOptions);
	}
	
	/**
	 * @return Request[]
	 */
	public function getRequests(): array
	{
		return $this->requests;
	}
}
