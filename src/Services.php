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
 */
class Services
{
	protected Container $container;
	
	public function __construct(
		Container $container,
	)
	{
		$this->container = $container;
	}
	
	/**
	 * @deprecated
	 */
	public static function getInstance(): ?self
	{
		return container()->get(static::class);
	}
	
	public function get(
		string $key,
		?string $registerClass = null,
	): ?Service
	{
		$service = $this->container->resolve($key);
		
		if($service === null)
		{
			if($registerClass !== null)
			{
				$this->register($key, $registerClass);
			}
			else
			{
				$this->register($key, Disabled::class);
			}
			
			$service = $this->container->get($key);
		}
		
		return $service;
	}
	
	public function __get(
		string $key,
	): ?Service
	{
		return $this->get($key);
	}
	
	public function register(
		string $key,
		string $serviceClass,
	): static
	{
		/** @var Service $serviceClass */
		$serviceClass::register($key, $this->container);
		
		return $this;
	}
	
	public function getConfig(): ArrayObject
	{
		return $this->container
			->get(Application::CONTAINER_KEY_CONFIG)
			->system->services;
	}
}
