<?php

namespace Ovos\Service;

use Ovos\ArrayObject;
use Ovos\Application;
use Ovos\Exception;
use Ovos\Error;
use Ovos\Service;
use Countable;
use Iterator;
use ErrorException;
use Throwable;
use function Ovos\services;

/**
 * Events
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
class Events extends Service implements Countable, Iterator
{
	/**
	 * @var string
	 */
	public const SYMBOL = 'events';

	/**
	 * List of events that occurred during runtime (such as errors or exceptions)
	 *
	 * @var array
	 */
	protected $_events = [];

	/**
	 * @return string
	 */
	public function getSymbol(): string
	{
		return self::SYMBOL;
	}

	/**
	 */
	public function __construct()
	{
		parent::__construct();

		$this->initErrorHandlers();
	}

	/**
	 * @return int
	 */
	public function count(): int
	{
		return \count($this->_events);
	}

	/**
	 * Sets up error handlers
	 */
	protected function initErrorHandlers()
	{
		set_error_handler(array($this, 'handleError'), E_ALL | E_STRICT);
		set_exception_handler(array($this, 'handleException'));
	}

	/**
	 * Handles errors
	 *
	 * @param int $errorCode
	 * @param string $errorString
	 * @param string $errorFile
	 * @param int $errorLine
	 *
	 * @throws ErrorException
	 *
	 * @return void
	 */
	public function handleError(int $errorCode, string $errorString, string $errorFile, int $errorLine): void
	{
		if(!(error_reporting() & $errorCode)) // error was not suppressed with @
		// or not included in error reporting
		{
			return;
		}

		throw new ErrorException($errorString, 0, $errorCode, $errorFile, $errorLine);
	}

	/**
	 * Handles errors
	 *
	 * @param int $errorCode
	 * @param string $errorString
	 * @param string $errorFile
	 * @param int $errorLine
	 * 
	 * @return void
	 */
	protected function _handleError(int $errorCode, string $errorString, string $errorFile, int $errorLine): void
	{
		$error = new Error($errorCode, $errorString, $errorFile, $errorLine);

		$this->add($error);
		$this->log($error);
	}

	/**
	 * Handles exceptions
	 *
	 * @param Throwable $exception
	 *
	 * @return void
	 */
	public function handleException(Throwable $exception): void
	{
		$this->add($exception);
		$this->log($exception);
	}

	/**
	 * Logs events (errors or exceptions)
	 *
	 * @param mixed[] ...$event
	 *
	 * @return $this
	 */
	public function log(...$event): self
	{
		services()->logger->log(...$event);
		
		return $this;
	}

	/**
	 * Adds an event (error or exception)
	 *
	 * @param mixed $event
	 *
	 * @return $this
	 */
	public function add($event): self
	{
		// save the event to display it later
		$this->_events[] = $event;
		
		return $this;
	}

	/**
	 * @return array
	 */
	public function toArray(): array
	{
		return $this->_events;
	}

	/**
	 * @return void
	 */
	public function rewind(): void
	{
		reset($this->_events);
	}

	/**
	 * @return mixed
	 */
	public function current()
	{
		return current($this->_events);
	}

	/**
	 * @return void
	 */
	public function next(): void
	{
		next($this->_events);
	}

	/**
	 * @return int|mixed|null|string
	 */
	public function key()
	{
		return key($this->_events);
	}

	/**
	 * @return bool
	 */
	public function valid(): bool
	{
		$key = $this->key();

		return ($key !== null && $key !== false);
	}
}
