<?php
declare(strict_types=1);

namespace Ovos\Service;

use Ovos\Client;
use Ovos\Exception;
use Ovos\Logger\Traits\TraitFile;
use Ovos\Service;
use Throwable;

use function count;
use function date;
use function get_class;
use function implode;
use function is_array;
use function is_numeric;
use function is_string;
use function json_encode;
use function mb_strlen;
use function method_exists;
use function preg_match;
use function sprintf;

/**
 * Logger
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
class Logger extends Service
{
	use TraitFile;
	
	public const string SYMBOL = 'logger';
	
	protected string $dir = 'events';
	
	protected string $file = 'events';
	
	protected array $remove = [
		'~^password.*~',
		'~Authorization~',
	];
	
	public function addRemove(
		array $remove,
	): static
	{
		$this->remove = array_merge($this->remove, $remove);
		
		return $this;
	}
	
	public function getRemove(): array
	{
		return $this->remove;
	}
	
	/**
	 * Logs events (messages/errors/exceptions)
	 */
	public function log(
		...$event,
	): static
	{
		$count = count($event);
		if($count === 0)
		{
			return $this;
		}
		
		$extras = [];
		// $message, sprintf arguments
		if(is_string($event[0])) // support string messages
		{
			$message = $event[0];
			if($count > 1)
			{
				$message = sprintf(...$event);
			}
			$event[0] = new Exception($message);
		}
		// $event, array $extras
		else if($count > 1)
		{
			$extras = $event[1];
		}
		
		$output = $this->getEvent($event[0]);
		
		// extras
		foreach($extras as $extra => $value)
		{
			$output.= $extra . ': ' . $value . PHP_EOL;
		}
		// prepend
		$prepend = $this->getPrepend();
		// append
		$append = $this->getAppend();
		
		$output = $prepend . $output . $append;
		$this->output($output);
		
		return $this;
	}
	
	public function getPrepend(): string
	{
		// prepend
		$prepend = date('c ');
		if($this->app->isInterfaceHttp())
		{
			$prepend.= sprintf
			(
				"%s: %s\n%s %s",
				$_SERVER['REQUEST_METHOD'],
				$_SERVER['REQUEST_URI'] ?? '',
				Client::getIp(),
				$_SERVER['HTTP_USER_AGENT'] ?? ''
			);
		}
		else if($this->app->isInterfaceCli())
		{
			$prepend.= 'CLI: ' . (isset($_SERVER['argv']) ?
				implode(' ', $_SERVER['argv']) : 'no arguments');
		}
		
		return $prepend . PHP_EOL;
	}
	
	public function getAppend(): string
	{
		// append
		$append = '';
		if(!empty($_GET))
		{
			$append.= 'GET: ' . PHP_EOL
				. json_encode($_GET, 
					JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT,
				) . PHP_EOL;
		}
		if(!empty($_POST))
		{
			$append.= 'POST: ' . PHP_EOL
				. json_encode($this->remove($_POST), 
					JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT,
				) . PHP_EOL;
		}
		if(!empty($_FILES))
		{
			$append.= 'FILES: ' . PHP_EOL
				. json_encode($_FILES, 
					JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT,
				) . PHP_EOL;
		}
		
		return $append . PHP_EOL;
	}
	
	public function remove(
		array $data,
	): array
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
			
			foreach($this->remove as $pattern)
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
	
	public function getEvent(
		object $event,
	): string
	{
		$output = '';
		
		if($event instanceof Throwable
			&& method_exists($event, 'getPrevious'))
		{
			$previous = false;
			do
			{
				$className = get_class($event);
				if($previous)
				{
					$className = "\nPrevious " . $className;
				}
				$output.= $event->getFile() . ':' . $event->getLine() . PHP_EOL;
				$output.= $className . ': ' . $event->getMessage()
					. PHP_EOL . $event->getTraceAsString();
				
				$previous = true;
			}
			while($event = $event->getPrevious());
		}
		
		return $output;
	}
}
