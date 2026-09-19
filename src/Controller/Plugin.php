<?php
declare(strict_types=1);

namespace Ovos\Controller;

use Ovos\Application;
use Ovos\Container;
use Ovos\Controller;
use Ovos\Request;

use function Ovos\container;

/**
 * Plugin
 *
 * @author Marcin Gil <mg@ovos.at>
 */
abstract class Plugin
{
	protected Container $container;
	
	protected Application $app;
	
	protected Request $request;
	
	protected Controller $controller;
	
	protected bool $enabled = true;
	
	/**
	 */
	public function __construct()
	{
		$this->container = container();
		$this->app = $this->container->get(Application::class);
		
		$this->request = $this->app->getRequest();
		// a plugin exists only inside a dispatch, so the controller is there;
		// the ?? keeps a boot without one from assigning null into a typed
		// property and blaming the plugin for it
		$controller = $this->app->getRequest()->getControllerInstance();
		if($controller !== null)
		{
			$this->controller = $controller;
		}
	}
	
	public function getRequest(): Request
	{
		return $this->request;
	}
	
	public function setController(
		Controller $controller,
	): static
	{
		$this->controller = $controller;
		
		return $this;
	}
	
	public function getController(): Controller
	{
		return $this->controller;
	}
	
	public static function getSymbol(): string
	{
		return static::SYMBOL;
	}
	
	public function preDispatch(): void
	{
	}
	
	public function postDispatch(): void
	{
	}
	
	public function setEnabled(
		bool $enabled = true,
	): static
	{
		$this->enabled = $enabled;
		
		return $this;
	}
	
	public function disable(): static
	{
		$this->enabled = false;
		
		return $this;
	}
	
	public function isEnabled(): bool
	{
		return $this->enabled;
	}
	
	public function _(
		string $phrase,
		...$params,
	): string
	{
		return $this->getController()
			->_($phrase, ...$params);
	}
	
	public function _n(
		string $phraseSingular,
		string $phrasePlural,
		int $n,
		...$params,
	): string
	{
		return $this->getController()
			->_n($phraseSingular, $phrasePlural, $n, ...$params);
	}
}
