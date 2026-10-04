<?php
declare(strict_types=1);

namespace Ovos;

use Ovos\Container\Inject;
use Ovos\Container\ArrayObject as InjectArrayObject;
use Ovos\Exception\UnavailableException;
use Ovos\Service\Logger;

use function microtime;
use function sprintf;

/**
 * Connection
 *
 * @author Marcin Gil <mg@ovos.at>
 */
abstract class Connection
{
	/**
	 * Seconds a failed connect is remembered before getClient() tries again
	 * (config `retry_after` overrides it)
	 */
	protected const float RETRY_AFTER = 2.0;
	
	#[Inject(Logger::SYMBOL)]
	protected ?Logger $logger = null;
	
	#[Inject('config')]
	#[InjectArrayObject('system', 'profilers')]
	protected ?ArrayObject $profilers = null;
	
	protected ArrayObject $config;
	
	/**
	 * When the last connect failed (microtime), null while none has
	 */
	protected ?float $failedAt = null;
	
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
	
	/**
	 * The client, or null when the server cannot be reached. A connect that
	 * failed is not tried again before `retry_after` seconds: an unreachable
	 * server costs one connect timeout per request (or per window in a
	 * long-running worker), not one per query
	 */
	public function getClient(): ?object
	{
		if($this->isConnected())
		{
			return $this->client;
		}
		
		if($this->failedAt !== null
			&& microtime(true) - $this->failedAt < (float)($this->config->retry_after ?? static::RETRY_AFTER))
		{
			return null;
		}
		
		if($this->connect())
		{
			$this->failedAt = null;
			
			return $this->client;
		}
		
		$this->failedAt = microtime(true);
		
		return null;
	}
	
	/**
	 * The client, or UnavailableException when the server cannot be reached —
	 * for a caller that cannot do its work without it (a store's query). The
	 * nullable getClient() stays for the callers that degrade on their own
	 * (a cache that falls through to its source)
	 *
	 * @throws UnavailableException
	 */
	public function requireClient(): object
	{
		return $this->getClient() ?? throw new UnavailableException(sprintf(
			'The connection to "%s" is unavailable.',
			static::getId($this->config),
		));
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
		$this->logger?->log(...$event);
		
		return $this;
	}
}
