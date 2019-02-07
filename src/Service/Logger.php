<?php
declare(strict_types=1);

namespace Ovos\Service;

use Ovos\Client;
use Ovos\Error;
use Ovos\Exception;
use Ovos\Logger as LoggerTrait;
use Ovos\Service;
use Throwable;
use function Ovos\app;

/**
 * Logger
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
class Logger extends Service
{
	use LoggerTrait\File;

	/**
	 * @var string
	 */
	public const SYMBOL = 'logger';

	/**
	 * @var string
	 */
	protected $_dir = 'events';
	
	/**
	 * @var string
	 */
	protected $_file = 'events';
	
	/**
	 * @return string
	 */
	public function getSymbol(): string
	{
		return self::SYMBOL;
	}

	/**
	 * Logs events (errors or exceptions)
	 *
	 * @param mixed[] ...$event
	 *
	 * @return void
	 */
	public function log(...$event): void
	{
		$count = \count($event);
		if($count === 0)
		{
			return;
		}

		$message = $event[0];
		if(\is_string($message)) // support string messages
		{
			if($count > 1)
			{
				$message = sprintf(...$event);
			}
			$event[0] = new Exception($message);
		}

		$output = static::getEvent($event[0]);

		// prepend
		$prepend = static::getPrepend();
		// append
		$append = static::getAppend();

		$output = $prepend . $output . $append;
		$this->output($output);
	}

	/**
	 * @return string
	 */
	public static function getPrepend(): string
	{
		// prepend
		$prepend = date('c ');
		if(app()->isInterfaceHttp())
		{
			$prepend.= sprintf
			(
				"%s: %s\n%s %s",
				$_SERVER['REQUEST_METHOD'],
				$_SERVER['REQUEST_URI'] ?? '',
				Client::getIP(),
				$_SERVER['HTTP_USER_AGENT'] ?? ''
			);
		}
		else if(app()->isInterfaceCli())
		{
			$prepend.= 'CLI: ' . (isset($_SERVER['argv']) ?
				implode(' ', $_SERVER['argv']) : 'no arguments');
		}

		return $prepend . PHP_EOL;
	}

	/**
	 * @return string
	 */
	public static function getAppend(): string
	{
		// append
		$append = PHP_EOL;
		if(!empty($_GET))
		{
			$append.= 'GET: ' . PHP_EOL
				. json_encode($_POST, JSON_PRETTY_PRINT) . PHP_EOL;
		}		
		if(!empty($_POST))
		{
			$append.= 'POST: ' . PHP_EOL
				. json_encode($_POST, JSON_PRETTY_PRINT) . PHP_EOL;
		}
		if(!empty($_FILES))
		{
			$append.= 'FILES: ' . PHP_EOL
				. json_encode($_FILES, JSON_PRETTY_PRINT) . PHP_EOL;
		}

		return $append . PHP_EOL;
	}

	/**
	 * @param object $event
	 *
	 * @return string
	 */
	public static function getEvent(object $event): string
	{
		$output = '';
		if($event instanceof Throwable && method_exists($event, 'getPrevious'))
		{
			$previous = false;
			do
			{
				$className = \get_class($event);
				if($previous) $className = "\nPrevious " . $className;
				$output.= $event->getFile() . ':' . $event->getLine() . PHP_EOL;
				$output.= $className . ': ' . $event->getMessage() . PHP_EOL . $event->getTraceAsString();

				$previous = true;
			}
			while($event = $event->getPrevious());
		}
		else
		{
			$name = $event instanceof Error ? $event->getName() : \get_class($event);
			$output.= $event->getFile() . ':' . $event->getLine() . PHP_EOL;
			$output.= $name . ': ' . $event->getMessage() . PHP_EOL . $event->getTraceAsString();
		}

		return $output;
	}
}
