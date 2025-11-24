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
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
abstract class Plugin
{
	/**
	 * @var Container
	 */
	protected Container $_container;
	
	/**
	 * @var Application
	 */
	protected Application $_app;
	
	/**
	 * @var Request
	 */
	protected Request $_request;
	
	/**
	 * @var Controller
	 */
	protected Controller $_controller;
	
	/**
	 * @var bool
	 */
	protected bool $_enabled = true;
	
	/**
	 */
	public function __construct()
	{
		$this->_container = container();
		$this->_app = $this->_container->get(Application::class);
		
		$this->_request = $this->_app->getRequest();
		$this->_controller = $this->_app->getRequest()->getControllerInstance();
	}
	
	/**
	 * @return Request
	 */
	public function getRequest(): Request
	{
		return $this->_request;
	}
	
	/**
	 * @param Controller $controller
	 *
	 * @return static
	 */
	public function setController(Controller $controller): static
	{
		$this->_controller = $controller;
		
		return $this;
	}
	
	/**
	 * @return Controller
	 */
	public function getController(): Controller
	{
		return $this->_controller;
	}
	
	/**
	 * @return string
	 */
	public static function getSymbol(): string
	{
		return static::SYMBOL;
	}
	
	/**
	 * @return void
	 */
	public function preDispatch(): void
	{
	}
	
	/**
	 * @return void
	 */
	public function postDispatch(): void
	{
	}
	
	/**
	 * @param bool $enabled
	 *
	 * @return static
	 */
	public function setEnabled(bool $enabled = true): static
	{
		$this->_enabled = $enabled;
		
		return $this;
	}
	
	/**
	 * @return static
	 */
	public function disable(): static
	{
		$this->_enabled = false;
		
		return $this;
	}
	
	/**
	 * @return bool
	 */
	public function isEnabled(): bool
	{
		return $this->_enabled;
	}
	
	/**
	 * @param string $phrase
	 * @param mixed ...$params
	 *
	 * @return string
	 */
	public function _(string $phrase, ...$params): string
	{
		return $this->getController()
			->_($phrase, ...$params);
	}
	
	/**
	 * @param string $phraseSingular
	 * @param string $phrasePlural
	 * @param int $n
	 * @param mixed ...$params
	 *
	 * @return string
	 */
	public function _n(string $phraseSingular,
		string $phrasePlural,
		int $n,
		...$params,
	): string
	{
		return $this->getController()
			->_n($phraseSingular, $phrasePlural, $n, ...$params);
	}
}
