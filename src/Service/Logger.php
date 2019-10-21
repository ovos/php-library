<?php
declare(strict_types=1);

namespace Ovos\Service;

use Ovos\Client;
use Ovos\Encryptor;
use Ovos\Error;
use Ovos\Exception;
use Ovos\Logger as LoggerTrait;
use Ovos\Service;
use Throwable;
use function Ovos\app;
use function Ovos\config;

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
	 * @var array
	 */
	protected $_remove = [
		'~^password.*~',
		'~Authorization~',
	];
	
	/**
	 * @return string
	 */
	public function getSymbol(): string
	{
		return self::SYMBOL;
	}
	
	/**
	 * @param array $remove
	 *
	 * @return $this
	 */
	public function addRemove(array $remove): self
	{
		$this->_remove = array_merge($this->_remove, $remove);

		return $this;
	}

	/**
	 * @return array
	 */
	public function getRemove(): array
	{
		return $this->_remove;
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
		$count = \count($event);
		if($count === 0)
		{
			return $this;
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

		$output = $this->getEvent($event[0]);

		// prepend
		$prepend = $this->getPrepend();
		// append
		$append = $this->getAppend();

		$output = $prepend . $output . $append;
		$this->output($output);
		
		return $this;
	}

	/**
	 * @return string
	 */
	public function getPrepend(): string
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
	public function getAppend(): string
	{
		// append
		$append = PHP_EOL;
		if(!empty($_GET))
		{
			$append.= 'GET: ' . PHP_EOL
				. json_encode($_GET, JSON_PRETTY_PRINT) . PHP_EOL;
		}		
		if(!empty($_POST))
		{
			$append.= 'POST: ' . PHP_EOL
				. json_encode($this->remove($_POST), JSON_PRETTY_PRINT) . PHP_EOL;
		}
		if(!empty($_FILES))
		{
			$append.= 'FILES: ' . PHP_EOL
				. json_encode($_FILES, JSON_PRETTY_PRINT) . PHP_EOL;
		}

		return $append . PHP_EOL;
	}

	/**
	 * @param array $data
	 * 
	 * @return array
	 */
	public function remove($data)
	{
		foreach($data as $key => $value)
		{
			if(is_array($value))
			{
				$data[$key] = $this->remove($value);
				
				continue;
			}
			
			if(is_numeric($key))
			{
				continue;
			}
			
			foreach($this->_remove as $pattern)
			{
				if(preg_match($pattern, $key, $matches))
				{
					$data[$key] = '[removed][length:' . mb_strlen($value) . ']';
					
					break;
				}
			}
		}
		
		return $data;
	}

	/**
	 * @param object $event
	 *
	 * @return string
	 */
	public function getEvent(object $event): string
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
