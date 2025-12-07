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
	#[Inject]
	protected Container $container;
	
	#[Inject] 
	protected Application $app;
	
	#[Inject] 
	protected Request $request;
	
	protected bool $enabled = true;
	
	public static function register(
		string $key,
		Container $container,
	): void
	{
		$container->registerClass($key, static::class);
	}
	
	public function isEnabled(): bool
	{
		return $this->enabled;
	}
	
	public function getSymbol(): string
	{
		return static::SYMBOL;
	}
}
