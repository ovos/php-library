<?php
declare(strict_types=1);

namespace Ovos;

use Ovos\Exception\RuntimeException;

/**
 * Service
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
abstract class Service
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

		$this->_dependsOn();
	}

	/**
	 * @throws RuntimeException
	 */
	protected function _dependsOn(): void
	{
		foreach($this->_dependsOn as $symbol)
		{
			if(Services::getInstance()->isRegistered($symbol) === false)
			{
				// just scream that we need it
				throw new RuntimeException('"%s" service depends on "%s" service.',
					$this->getSymbol(), $symbol);
			}
		}
	}

	/**
	 * @param bool $enabled
	 *
	 * @return $this
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

	/**
	 * @return string
	 */
	abstract public function getSymbol(): string;
}
