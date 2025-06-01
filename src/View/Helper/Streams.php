<?php
declare(strict_types=1);

namespace Ovos\View\Helper;

use Ovos\ArrayObject;
use Ovos\Container\Inject;
use Ovos\Service\Streams as Service;
use Ovos\View;
use Ovos\View\Helper;

/**
 * Streams
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
class Streams extends Helper
{
	/**
	 * @var ArrayObject
	 */
	protected ArrayObject $_config;

	/**
	 * @var ?Service
	 */
	protected ?Service $_streamsService;
	
	/**
	 * @param ?Service $streamsService
	 * @param ArrayObject $config
	 */
	public function __construct(
		#[Inject(Service::SYMBOL)] ?Service $streamsService,
		#[Inject('config')]
		ArrayObject $config,
	)
	{
		parent::__construct();
		
		$this->_streamsService = $streamsService;
		$this->_config = $config;
	}
	
	/**
	 * @return self
	 */
	public function streams(): self
	{
		return $this;
	}
	
	/**
	 * @return ?Service
	 */
	public function get(): ?Service
	{
		return $this->_streamsService;
	}
	
	/**
	 * @return array
	 */
	public function getRequests(): array
	{
		return $this->_streamsService !== null
			? $this->_streamsService->getRequests()
			: [];
	}
	
	/**
	 * @return string
	 */
	public function __toString()
	{
		if($this->_config->system->profilers->enabled === false)
		{
			return '';
		}
		
		$requests = $this->getRequests();
		if(empty($requests))
		{
			return '';
		}
		
		$view = new View('helpers/streams.phtml');
		$view->requests = $requests;
		
		return $view->__toString();
	}
}
