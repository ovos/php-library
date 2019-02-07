<?php
declare(strict_types=1);

namespace Ovos\Controller;
use Ovos\Application;
use Ovos\Controller;
use Ovos\Exception;

/**
 * Cli
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
class Cli extends Controller
{
	/**
	 * @var bool
	 */
	protected $_allowHttpAccess = true;

	/**
	 * Allows to access specified CLI methods via HTTP
	 *
	 * @var array
	 */
	protected $_httpActions = [];

	/**
	 * @param bool $allowHttpAccess
	 *
	 * @return $this
	 */
	public function setAllowHttpAccess(bool $allowHttpAccess): self
	{
		$this->_allowHttpAccess = $allowHttpAccess;

		return $this;
	}

	/**
	 * @return bool
	 */
	public function isAllowedHttpAccess(): bool
	{
		return $this->_allowHttpAccess;
	}

	/**
	 * @param string $action
	 *
	 * @return $this
	 */
	public function addHttpAction($action): self
	{
		$this->_httpActions[] = $action;

		return $this;
	}

	/**
	 * @return array
	 */
	public function getHttpActions(): array
	{
		return $this->_httpActions;
	}

	/**
	 * preDispatch
	 */
	public function preDispatch(): void
	{
		if($this->getRequest()->isCli() === false
			&& ($this->isAllowedHttpAccess()
				&& \in_array($this->getRequest()->getAction(), $this->_httpActions, true) === false))
		{
			throw new Exception('Forbidden.');
		}
	}
}
