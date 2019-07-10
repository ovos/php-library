<?php
declare(strict_types=1);

namespace Ovos\Controller;

use Ovos\Application;
use Ovos\Controller;
use Ovos\Exception;
use function Ovos\services;
use Plugins\Auth;

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
	protected $_allowHttpAccess = false;

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
	 * @param array $actions
	 *
	 * @return $this
	 */
	public function addHttpActions(array $actions): self
	{
		$this->_httpActions = array_merge($this->_httpActions, $actions);

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
		parent::preDispatch();
	
		if($this->getRequest()->isCli() === true)
		{
			return;
		}
		
		if($this->isAllowedHttpAccess())
		{
			return;
		}
	
		if(\in_array($this->getRequest()->getAction(), $this->_httpActions, true) === true)
		{
			return;
		}
		
		throw new Exception('Forbidden.');
	}
}
