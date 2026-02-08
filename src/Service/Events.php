<?php

namespace Ovos\Service;

use Ovos\Service;
use Countable;
use Iterator;
use ErrorException;
use Throwable;

use function count;
use function set_error_handler;
use function set_exception_handler;
use function error_reporting;
use function reset;
use function current;
use function next;
use function key;

/**
 * Events
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Events
	extends Service
	implements Countable, Iterator
{
	public const string SYMBOL = 'events';
	
	/**
	 * List of events that occurred during runtime (such as errors or exceptions)
	 */
	protected array $events = [];
	
	public function __construct()
	{
		$this->initErrorHandlers();
	}
	
	public function count(): int
	{
		return count($this->events);
	}
	
	/**
	 * Handles exceptions
	 */
	public function handleException(
		Throwable $exception,
	): void
	{
		$this->add($exception);
		$this->log($exception);
	}
	
	/**
	 * Handles errors
	 */
	public function handleError(
		int $errorCode,
		string $errorString,
		string $errorFile,
		int $errorLine,
	): void
	{
		if((error_reporting() & $errorCode) === 0) // error was not suppressed with @
		// or not included in error reporting
		{
			return;
		}
		
		throw new ErrorException($errorString, 
			0,
			$errorCode,
			$errorFile,
			$errorLine,
		);
	}
	
	/**
	 * Sets up error handlers
	 */
	protected function initErrorHandlers(): void
	{
		set_error_handler([$this, 'handleError'], E_ALL);
		set_exception_handler([$this, 'handleException']);
	}
	
	/**
	 * Logs events (messages/errors/exceptions)
	 */
	public function log(
		...$event,
	): static
	{
		$this->container
			->get(Logger::SYMBOL)
			->log(...$event);
		
		return $this;
	}
	
	/**
	 * Adds an event (error or exception)
	 */
	public function add(
		mixed $event,
	): static
	{
		// save the event to display it later
		$this->events[] = $event;
		
		return $this;
	}
	
	public function toArray(): array
	{
		return $this->events;
	}
	
	public function rewind(): void
	{
		reset($this->events);
	}
	
	public function current(): mixed
	{
		return current($this->events);
	}
	
	public function next(): void
	{
		next($this->events);
	}
	
	public function key(): int|string|null
	{
		return key($this->events);
	}
	
	public function valid(): bool
	{
		$key = $this->key();
		
		return $key !== null;
	}
}
