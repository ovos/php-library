<?php
declare(strict_types=1);

namespace Ovos;

use Ovos\Exception\RuntimeException;
use Ovos\Container\Inject;

/**
 * Service
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
abstract class Service
{
	/**
	 * @var Container
	 */
	#[Inject]
	protected Container $_container;
	
	/**
	 * @var Application
	 */
	#[Inject] 
	protected Application $_app;
	
	/**
	 * @var Request
	 */
	#[Inject] 
	protected Request $_request;
	
	/**
	 * @var bool
	 */
	protected bool $_enabled = true;
	
	/**
	 * @var array
	 */
	protected array $_dependsOn = [];
	
	/**
	 */
	public function __construct()
	{
		$this->_dependsOn();
	}
	
	/**
	 * @throws RuntimeException
	 */
	protected function _dependsOn(): void
	{
		foreach($this->_dependsOn as $symbol)
		{
			if($this->_container->isRegistered($symbol) === false)
			{
				// just scream that we need it
				throw new RuntimeException(
					'"%s" service depends on "%s" service.',
					$this->getSymbol(), $symbol);
			}
		}
	}
	
	/**
	 * @param Container $container
	 * @param ?string $key
	 *
	 * @return void
	 */
	public static function register(Container $container,
		?string $key = null,
	): void
	{
		$key = $key ?? static::SYMBOL;
		$container->registerClass($key, static::class);
	}
	
	/**
	 * @param bool $enabled
	 *
	 * @return self
	 */
	public function setEnabled(bool $enabled): self
	{
		$this->_enabled = $enabled;
		
		return $this;
	}
	
	/**
	 * @return bool
	 */
	public function isEnabled(): bool
	{
		return $this->_enabled;
	}
}
