<?php
declare(strict_types=1);

namespace Ovos;

use Ovos\Container\Inject;
use Ovos\Container\ArrayObject as InjectArrayObject;
use Ovos\Service\Logger;

/**
 * Connection
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
abstract class Connection
{
	/**
	 * @var ?Logger
	 */
	#[Inject(Logger::SYMBOL)]
	protected ?Logger $_logger = null;
	
	/**
	 * @var ArrayObject
	 */
	#[Inject('config')]
	#[InjectArrayObject('system', 'profilers')]
	protected ArrayObject $_profilers;
	
	/**
	 * @var ArrayObject
	 */
	protected ArrayObject $_config;
	
	/**
	 * @param ArrayObject $config
	 */
	public function __construct(ArrayObject $config)
	{
		$this->setConfig($config);
	}
	
	/**
	 * @param ArrayObject $config
	 * 
	 * @return self
	 */
	public function setConfig(ArrayObject $config): self
	{
		$this->_config = $config;
		
		return $this;
	}
	
	/**
	 * @return ArrayObject
	 */
	public function getConfig(): ArrayObject
	{
		return $this->_config;
	}
	
	/**
	 * @return ?object
	 */
	abstract public function getClient(): ?object;
	
	/**
	 * @return bool
	 */
	abstract public function connect(): bool;
	
	/**
	 * @return ?object
	 */
	public function getConnectedClient(): ?object
	{
		if($this->isConnected()
			|| $this->connect())
		{
			return $this->getClient();
		}
		
		return null;
	}
	
	/**
	 * @return bool
	 */
	public function isConnected(): bool
	{
		return $this->_client !== null;
	}
	
	/**
	 * @param ArrayObject $config
	 *
	 * @return string
	 */
	public static function getId(ArrayObject $config): string
	{
		return (string)$config->database;
	}
	
	/**
	 * Logs events (messages/errors/exceptions)
	 *
	 * @param mixed ...$event
	 *
	 * @return self
	 */
	public function log(...$event): self
	{
		$this->_logger->log(...$event);
		
		return $this;
	}
}
