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
	 * @param string $key
 	 * @param Container $container
	 *
	 * @return void
	 */
	public static function register(string $key,
		Container $container,
	): void
	{
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
