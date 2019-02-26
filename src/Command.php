<?php

namespace Ovos;

/**
 * Command
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
class Command
{
	/**
	 * Application
	 *
	 * @var Application
	 */
	protected $_app;

	/**
	 * @var string
	 */
	protected $_name;

	/**
	 * @var array
	 */
	protected $_params;

	/**
	 * @param string $name
	 * @param array $params
	 */
	public function __construct($name, $params = [])
	{
		$this->_app = app();

		$this->setName($name);
		$this->setParams($params);
	}

	/**
	 * @param string $name
	 * 
	 * @return $this
	 */
	public function setName(string $name): self
	{
		$this->_name = $name;
		
		return $this;
	}

	/**
	 * @param array $params
	 * 
	 * @return $this
	 */
	public function setParams(array $params): self
	{
		$this->_params = $params;
		
		return $this;
	}

	/**
	 * @return ArrayObject
	 */
	public function getConfig(): ArrayObject
	{
		return $this->_app->getConfig()->commands;
	}

	/**
	 * @return string
	 */
	public function get(): string
	{
		$commands = $this->getConfig();
		if(!isset($commands[$this->_name]))
		{
			return '';
		}

		$command = $commands[$this->_name];

		if(\count($this->_params))
		{
			$command = str_replace(array_keys($this->_params), array_values($this->_params), $command);
		}
		
		return $command;
	}

	/**
	 * @return string
	 */
	public function __toString()
	{
		return $this->get();
	}
}
