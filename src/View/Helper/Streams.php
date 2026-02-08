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
 * @author Marcin Gil <mg@ovos.at>
 */
class Streams extends Helper
{
	protected ArrayObject $config;
	
	protected ?Service $streamsService;
	
	public function __construct(
		#[Inject(Service::SYMBOL)] ?Service $streamsService,
		#[Inject('config')]
		ArrayObject $config,
	)
	{
		parent::__construct();
		
		$this->streamsService = $streamsService;
		$this->config = $config;
	}
	
	public function streams(): static
	{
		return $this;
	}
	
	public function get(): ?Service
	{
		return $this->streamsService;
	}
	
	public function getRequests(): array
	{
		return $this->streamsService !== null
			? $this->streamsService->getRequests()
			: [];
	}
	
	public function __toString()
	{
		if($this->config->system->profilers->enabled === false)
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
