<?php
declare(strict_types=1);

namespace Ovos;

use Ovos\Service\Auth;
use Ovos\Service\Benchmark;
use Ovos\Service\Events;
use Ovos\Service\Logger;
use Ovos\Service\Session;
use Ovos\Service\Cookies;
use Ovos\Service\Cache;
use Ovos\Service\Database;
use Sonorys\Service\Mvno;
use QueryTec\Service\Identify;

/**
 * Services
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 *
 * @property Auth $auth
 * @property Benchmark $benchmark
 * @property Events $events
 * @property Logger $logger
 * @property Session $session
 * @property Cookies $cookies
 * @property Cache $cache
 * @property Database $database
 * @property Mvno $mvno
 * @property Identify $identify
 */
class Services
{
	use Singleton;

	/**
	 * @var Service[]
	 */
	protected $_items = [];

	/**
	 * @param string $symbol
	 *
	 * @return Service|null
	 */
	public function __get(string $symbol): ?Service
	{
		if(isset($this->_items[$symbol]))
		{
			$service = $this->_items[$symbol];
			if($service->isEnabled() === false)
			{
				return null;
			}

			return $service;
		}

		return null;
	}

	/**
	 * @param Service $service
	 * @param string $symbol
	 *
	 * @return $this
	 */
	public function register(Service $service, string $symbol = null): self
	{
		if($symbol === null)
		{
			$symbol = $service->getSymbol();
		}

		$this->_items[$symbol] = $service;

		return $this;
	}

	/**
	 * @param string $symbol
	 *
	 * @return bool
	 */
	public function isRegistered(string $symbol): bool
	{
		return isset($this->_items[$symbol]);
	}

	/**
	 * @return array
	 */
	public function toArray(): array
	{
		return $this->_items;
	}
}
