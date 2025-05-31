<?php
declare(strict_types=1);

namespace Ovos\View\Helper;

use Ovos\View;
use Ovos\View\Helper;
use Ovos\Service\Streams as Service;

use function Ovos\services;

/**
 * Streams
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
class Streams extends Helper
{
	/**
	 * @var ?Service
	 */
	protected ?Service $_streamsService;
	
	/**
	 * @param ?Service $streamsService
	 */
	public function __construct(
		#[Inject(Service::SYMBOL)] ?Service $streamsService,
	)
	{
		parent::__construct();
		
		$this->_streamsService = $streamsService;
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
		if($this->_app->getConfig()->system->profilers->enabled === false)
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
