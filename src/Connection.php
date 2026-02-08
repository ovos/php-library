<?php
declare(strict_types=1);

namespace Ovos;

use Ovos\Container\Inject;
use Ovos\Container\ArrayObject as InjectArrayObject;
use Ovos\Service\Logger;

/**
 * Connection
 *
 * @author Marcin Gil <mg@ovos.at>
 */
abstract class Connection
{
	#[Inject(Logger::SYMBOL)]
	protected ?Logger $logger = null;
	
	#[Inject('config')]
	#[InjectArrayObject('system', 'profilers')]
	protected ArrayObject $profilers;
	
	protected ArrayObject $config;
	
	public function __construct(
		ArrayObject $config,
	)
	{
		$this->setConfig($config);
	}
	
	public function setConfig(
		ArrayObject $config,
	): static
	{
		$this->config = $config;
		
		return $this;
	}
	
	public function getConfig(): ArrayObject
	{
		return $this->config;
	}
	
	public function getClient(): ?object
	{
		if($this->isConnected()
			|| $this->connect())
		{
			return $this->client;
		}
		
		return null;
	}
	
	abstract public function connect(): bool;
	
	public function isConnected(): bool
	{
		return $this->client !== null;
	}
	
	public static function getId(
		ArrayObject $config,
	): string
	{
		return (string)$config->database;
	}
	
	/**
	 * Logs events (messages/errors/exceptions)
	 */
	public function log(
		...$event,
	): static
	{
		$this->logger->log(...$event);
		
		return $this;
	}
}
