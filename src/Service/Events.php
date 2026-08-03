<?php

namespace Ovos\Service;

use Ovos\Logger\Normalizer;
use Ovos\Logger\Writer;
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
	
	/**
	 * Log writers, built lazily from what is registered (the file Logger and,
	 * when the sender is registered, the console Writer). Null until first use.
	 */
	protected ?array $writers = null;
	
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
	 * Logs an event (message/error/exception) by fanning it out to every
	 * registered writer — the file Logger and, when the error console sender is
	 * registered, the console Writer. Each writer is isolated so one failure
	 * neither blocks the others nor re-enters the log path (which would recurse).
	 *
	 * The named priority argument selects the severity of a logged message —
	 * log('disk almost full on %s', $host, priority: Priority::WARNING); a
	 * plain string logs as NOTICE (see Normalizer for the full contract).
	 */
	public function log(
		...$event,
	): static
	{
		$normalized = Normalizer::normalize($event);
		if($normalized === null)
		{
			return $this;
		}
		
		[$throwable, $extra] = $normalized;
		
		foreach($this->getWriters() as $writer)
		{
			try
			{
				$writer->write($throwable, $extra);
			}
			catch(Throwable)
			{
				// one writer's failure must not block the others — and must
				// never be logged from here (that would recurse)
			}
		}
		
		return $this;
	}
	
	/**
	 * The registered log writers, built lazily: the file Logger, plus the
	 * console Writer when the sender is registered (the isRegistered gate keeps
	 * the console optional per environment).
	 *
	 * @return Writer[]
	 */
	protected function getWriters(): array
	{
		if($this->writers !== null)
		{
			return $this->writers;
		}
		
		$this->writers = [];
		
		// file writer — the Logger service
		if($this->container->isRegistered(Logger::SYMBOL))
		{
			$this->writers[] = $this->container->get(Logger::SYMBOL);
		}
		
		// console writer — only when the error console sender is registered
		if($this->container->isRegistered(Console\Sender::SYMBOL))
		{
			$this->writers[] = new Console\Writer(
				$this->container->get(Console\Sender::SYMBOL),
			);
		}
		
		return $this->writers;
	}
	
	/**
	 * Register an extra log writer beyond the file/console defaults.
	 */
	public function addWriter(
		Writer $writer,
	): static
	{
		if($this->writers === null)
		{
			$this->getWriters();
		}
		
		$this->writers[] = $writer;
		
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
	
	/**
	 * Clears the collected events
	 */
	public function clear(): static
	{
		$this->events = [];
		
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
