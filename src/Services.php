<?php
declare(strict_types=1);

namespace Ovos;

use Ovos\Service\Memory;
use Ovos\Service\Benchmark;
use Ovos\Service\Events;
use Ovos\Service\Logger;
use Ovos\Service\Session;
use Ovos\Service\Cookies;
use Ovos\Service\Cache;
use Ovos\Service\Database;

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
	/**
	 * @var Container 
	 */
	protected Container $_container;
	
	/**
	 * @param Container $container
	 */
	public function __construct(Container $container)
	{
		$this->_container = $container;
	}
	
	/**
	 * @param string $key
	 * @param ?string $registerClass
	 *
	 * @return ?Service
	 */
	public function get(
		string $key,
		?string $registerClass = null,
	): ?Service
	{
		$service = $this->_container->get($key);
		
		if($service === null
			&& $registerClass !== null)
		{
			$this->register($registerClass, $key);
			$service = $this->_container->get($key);
		}
		
		return $service;
	}
	
	/**
	 * @param string $key
	 *
	 * @return ?Service
	 */
	public function __get(string $key): ?Service
	{
		return $this->get($key);
	}
	
	/**
	 * @param string $serviceClass
	 * @param ?string $key
	 *
	 * @return self
	 */
	public function register(string $serviceClass,
		?string $key = null,
	): self
	{
		/** @var Service $serviceClass */
		$serviceClass::register($this->_container, $key);
		
		return $this;
	}
	
	/**
	 * @param string $key
	 *
	 * @return bool
	 */
	public function isRegistered(string $key): bool
	{
		return $this->_container->isRegistered($key);
	}
	
	/**
	 * @return ArrayObject
	 */
	public function getConfig(): ArrayObject
	{
		return $this->_container->get(Application::KEY_CONFIG)
			->system->services;
	}
}
