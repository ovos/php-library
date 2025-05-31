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
	 * @return bool
	 */
	public function isEnabled(): bool
	{
		return $this->_enabled;
	}
	
	/**
	 * @return string
	 */
	public function getSymbol(): string
	{
		return static::SYMBOL;
	}
}
