<?php
declare(strict_types=1);

namespace Ovos\Service;

use Ovos\Service;
use Ovos\ArrayObject;
use Ovos\Exception;
use Ovos\Arrays;
use Ovos\Stream\Request;

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
	protected array $_defaultContext = [];	
	
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
		
		$this->_defaultContext = [
			'http' => [
				'method' => Request::METHOD_GET,
				'timeout' => $this->_config->timeout ?: 15,
			],
		];
		
		if($this->_config->username && $this->_config->password)
		{
			$auth = base64_encode(sprintf('%s:%s',
				$this->_config->username,
				$this->_config->password,
			));
			
			$this->_defaultContext['http']['header'] = 'Authorization: Basic ' . $auth;
		}
	}
	
	/**
	 * @return self
	 */
	public function setDefaultContext(array $defaultContext): self
	{
		$this->_defaultContext = $defaultContext;
		
		return $this;
	}
	
	/**
	 * @return array
	 */
	public function getDefaultContext(): array
	{
		return $this->_defaultContext;
	}
	
	/**
	 * @param string $url
	 * @param array $context
	 *
	 * @return mixed
	 */
	public function request(string $url, array $context = []): mixed
	{
		$requestContext = stream_context_create(Arrays::deepMerge($this->_defaultContext, $context));
		$request = new Request($this->_config->url . $url);
		$object = $request->invoke($requestContext)->getJsonResponse();
		$this->_requests[] = $request;
		
		return $object;
	}
	
	/**
	 * @return Request[]
	 */
	public function getRequests(): array
	{
		return $this->_requests;
	}
}
