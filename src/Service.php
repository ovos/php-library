<?php
declare(strict_types=1);

namespace Ovos;

use Ovos\Container\Inject;

/**
 * Service
 *
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
	
	/** Called for each application, including when this service is reused. */
	public function boot(Application $app): void
	{
		$this->app = $app;
		$this->request = $app->getRequest();
	}

	/** Called after response callbacks, before collected events are cleared. */
	public function terminate(): void
	{
	}

	public function getSymbol(): string
	{
		return static::SYMBOL;
	}
}
