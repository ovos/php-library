<?php
declare(strict_types=1);

namespace Ovos;

use Ovos\Service\Disabled;
use Ovos\Service\Memory;
use Ovos\Service\Benchmark;
use Ovos\Service\Events;
use Ovos\Service\Logger;
use Ovos\Service\Session;
use Ovos\Service\Cookies;
use Ovos\Service\Cache;
use Ovos\Service\Database;
use function Ovos\config;

/**
 * Services
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 * 
 * @property Memory $memory
 * @property Benchmark $benchmark
 * @property Events $events
 * @property Logger $logger
 * @property Session $session
 * @property Cookies $cookies
 * @property Cache $cache
 * @property Database $database
 */
class Services
{
	use Singleton;

	/**
	 * @var Service[]
	 */
	protected static $_items = []; // static in case of changing instance with newInstance (after loading the config)

	/**
	 * @param string $symbol
	 *
	 * @return null|Service
	 */
	public function get(string $symbol): null|Service
	{
		if(isset(self::$_items[$symbol]))
		{
			$service = self::$_items[$symbol];
			if($service->isEnabled() === false)
			{
				return new Disabled;
			}

			return $service;
		}

		return new Disabled;
	}

	/**
	 * @param string $symbol
	 *
	 * @return null|Service
	 */
	public function __get(string $symbol): null|Service
	{
		return $this->get($symbol);
	}

	/**
	 * @param Service $service
	 * @param null|string $symbol
	 *
	 * @return self
	 */
	public function register(Service $service, null|string $symbol = null): self
	{
		if($symbol === null)
		{
			$symbol = $service->getSymbol();
		}

		self::$_items[$symbol] = $service;

		return $this;
	}

	/**
	 * @param string $symbol
	 *
	 * @return bool
	 */
	public function isRegistered(string $symbol): bool
	{
		return isset(self::$_items[$symbol]);
	}

	/**
	 * @return ArrayObject
	 */
	public function getConfig(): ArrayObject
	{
		return config()->system->services;
	}

	/**
	 * @return array
	 */
	public function toArray(): array
	{
		return self::$_items;
	}
}
