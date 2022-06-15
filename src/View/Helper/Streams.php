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
	 * @return self
	 */
	public function streams(): self
	{
		return $this;
	}

	/**
	 * @return Service
	 */
	public function get(): Service
	{
		return services()->streams;
	}

	/**
	 * @return array
	 */
	public function getRequests(): array
	{
		return services()->streams->getRequests();
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
