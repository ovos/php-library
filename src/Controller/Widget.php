<?php
declare(strict_types=1);

namespace Ovos\Controller;

use Ovos\Application;
use Ovos\Container;
use Ovos\Request;
use Ovos\Translatable;


use function Ovos\container;

/**
 * Widget
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
abstract class Widget
{
	use Translatable;
	
	/**
	 * @var Container
	 */
	protected Container $_container;
	
	/**
	 * Application
	 *
	 * @var Application
	 */
	protected Application $_app;
	
	/**
	 * @var Request
	 */
	protected Request $_request;
	
	/**
	 * @var string
	 */
	protected string $_script;
	
	/**
	 */
	public function __construct()
	{
		$this->_container = container();
		$this->_app = $this->_container->get(Application::class);
		
		$this->_request = $this->_app->getRequest();
	}
	
	/**
	 * @param string $script
	 *
	 * @return static
	 */
	public function setScript(string $script): static
	{
		$this->_script = $script;
		
		return $this;
	}
	
	/**
	 * @return string
	 */
	public function getScript(): string
	{
		return $this->_script;
	}
	
	/**
	 * @return string
	 */
	abstract public function render(): string;
	
	/**
	 * @return string
	 */
	abstract public function __toString(): string;
}
