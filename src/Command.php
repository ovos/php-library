<?php

namespace Ovos;

use function count;
use function str_replace;
use function array_keys;
use function array_values;

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
	protected Application $_app;
	
	/**
	 * @var string
	 */
	protected string $_name;
	
	/**
	 * @var array
	 */
	protected array $_params;
	
	/**
	 * @param string $name
	 * @param array $params
	 */
	public function __construct(string $name, array $params = [])
	{
		$this->_app = app();
		
		$this->setName($name);
		$this->setParams($params);
	}
	
	/**
	 * @param string $name
	 *
	 * @return static
	 */
	public function setName(string $name): static
	{
		$this->_name = $name;
		
		return $this;
	}
	
	/**
	 * @param array $params
	 *
	 * @return static
	 */
	public function setParams(array $params): static
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
		if(isset($commands[$this->_name]) === false)
		{
			return '';
		}
		
		$command = $commands[$this->_name];
		
		if(count($this->_params))
		{
			$command = str_replace(array_keys($this->_params), array_values($this->_params), $command);
		}
		
		return $command;
	}
	
	/**
	 * @return string
	 */
	public function __toString(): string
	{
		return $this->get();
	}
}
