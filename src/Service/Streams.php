<?php
declare(strict_types=1);

namespace Ovos\Service;

use Ovos\Service;
use Ovos\ArrayObject;
use Ovos\Exception;
use Ovos\Arrays;
use Ovos\Stream\Request;
use Ovos\Stream\Request\Factory;

/**
 * Streams
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
class Streams extends Service
{
	/**
	 * @var string
	 */
	public const SYMBOL = 'streams';
	
	/**
	 * @var ?ArrayObject 
	 */
	protected ?ArrayObject $_config = null;	
	
	/**
	 * @var array
	 */
	protected array $_defaultContextOptions = [];	
	
	/**
	 * @var Request[]
	 */
	protected array $_requests = [];

	/**
	 * @return string
	 */
	public function getSymbol(): string
	{
		return self::SYMBOL;
	}
	
	/**
	 */
	public function __construct()
	{
		parent::__construct();
		
		$this->_config = $this->_app->getConfig()->get('streams');
		if($this->_config === null)
		{
			throw new Exception('"streams" config section is missing.');
		}
		
		$this->_defaultContextOptions = [
			'http' => [
				'timeout' => $this->_config->timeout ?: 15,
			],
		];
		
		if($this->_config->username && $this->_config->password)
		{
			$auth = base64_encode(sprintf('%s:%s',
				$this->_config->username,
				$this->_config->password,
			));
			
			$this->_defaultContextOptions['http']['header'] = 'Authorization: Basic ' . $auth;
		}
	}
	
	/**
	 * @param array $defaultContextOptions
	 *
	 * @return $this
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
	
	/**
	 * @param string $url
	 * @param array $contextOptions
	 *
	 * @return mixed
	 * 
	 * @throws ErrorException (from fopen)
	 */
	public function request(string $url, array $contextOptions = []): mixed
	{
		$requestUrl = ($this->_config->base_url ?? $this->_config->url) . $url; // -> url for BC
		$request = (new Request($requestUrl, $this->_defaultContextOptions))
			->setContextOptions($contextOptions);
		$object = $request->invoke()->getJsonResponse();
		$this->_requests[] = $request;
		
		return $object;
	}
	
	/**
	 * Pass a request created with factory() method
	 * 
	 * @param Request $request
	 *
	 * @return mixed
	 * 
	 * @throws ErrorException (from fopen)
	 */
	public function invoke(Request $request): mixed
	{
		$object = $request->invoke()->getJsonResponse();
		$this->_requests[] = $request;
		
		return $object;
	}
	
	/**
	 * @param string $baseUrl
	 * @param array $defaultContextOptions
	 *
	 * @return Factory
	 */
	public function factory(string $baseUrl,
		array $defaultContextOptions = []): Factory
	{
		return (new Factory($baseUrl, $this->_defaultContextOptions))
			->setDefaultContextOptions($defaultContextOptions);
	}
	
	/**
	 * @return Request[]
	 */
	public function getRequests(): array
	{
		return $this->_requests;
	}
}
