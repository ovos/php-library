<?php
declare(strict_types=1);

namespace Ovos\Controller;

use Ovos\Application;
use Ovos\Controller;
use Ovos\Request;
use Ovos\Exception\RuntimeException;

use function Ovos\app;

/**
 * Plugin
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
abstract class Plugin
{
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
	 * @var array
	 */
	protected array $_dependsOn = [];
	
	/**
	 */
	public function __construct()
	{
		$this->_app = app();
		$this->_request = $this->_app->getRequest();
		$this->_controller = $this->_app->getRequest()->getControllerInstance();
		
		$this->_dependsOn();
	}
	
	/**
	 * @throws RuntimeException
	 */
	protected function _dependsOn(): void
	{
		foreach($this->_dependsOn as $symbol)
		{
			if($this->getController()->hasPlugin($symbol) === false)
			{
				// just scream that we need it
				throw new RuntimeException('"%s" plugin depends on "%s" plugin.',
					static::getSymbol(), $symbol);
			}
		}
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
	 * @return self
	 */
	public function setController(Controller $controller): self
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
	abstract public static function getSymbol(): string;
	
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
	 * @return self
	 */
	public function setEnabled(bool $enabled = true): self
	{
		$this->_enabled = $enabled;
		
		return $this;
	}
	
	/**
	 * @return self
	 */
	public function disable(): self
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
		return $this->getController()->_(
			$phrase, ...$params);
	}
	
	/**
	 * @param string $phraseSingular
	 * @param string $phrasePlural
	 * @param int $n
	 * @param mixed ...$params
	 *
	 * @return string
	 */
	public function _n(string $phraseSingular, string $phrasePlural, int $n, ...$params): string
	{
		return $this->getController()->_n(
			$phraseSingular, $phrasePlural, $n, ...$params);
	}
}
