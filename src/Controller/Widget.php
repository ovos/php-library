<?php
declare(strict_types=1);

namespace Ovos\Controller;

use Ovos\Application;
use Ovos\Request;
use Ovos\Response;
use Ovos\Translatable;
use function Ovos\app;

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
	 * Application
	 *
	 * @var Application
	 */
	protected $_app;

	/**
	 * @var Request
	 */
	protected $_request;

	/**
	 * @var string
	 */
	protected $_script;

	/**
	 */
	public function __construct()
	{
		$this->_app = app();
		$this->_request = $this->_app->getRequest();
	}

	/**
	 * @param string $script
	 *
	 * @return $this
	 */
	public function setScript(string $script): self
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
