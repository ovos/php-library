<?php
declare(strict_types=1);

namespace Ovos\Service;

use Ovos\Application;
use Ovos\Client;
use Ovos\Logger\Normalizer;
use Ovos\Logger\Traits\TraitFile;
use Ovos\Logger\Writer;
use Ovos\Service;
use Throwable;

/**
 * Logger
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Logger extends Service implements Writer
{
	use TraitFile;
	
	public const string SYMBOL = 'logger';
	
	protected string $dir = 'events';
	
	protected string $file = 'events';
	
	/** Field-name patterns whose values are replaced with [redacted]. */
	protected array $remove = [
		'~pass(word|wd)?~i',
		'~pwd~i',
		'~token~i',
		'~secret~i',
		'~authorization~i',
		'~cookie~i',
		'~api[_-]?key~i',
		'~jwt~i',
		'~bearer~i',
		'~signature~i',
		'~credential~i',
		'~(private|access|consumer|licen[cs]e|encryption|signing)[_-]?key~i',
		'~backup[_-]?codes?~i',
		'~auth[_-]?code~i',
		'~csrf|xsrf~i',
		'~sess(ion)?[_-]?id~i',
		// a one-time code and a bare `auth` only as a word of the name:
		// otpaper and author stay readable
		'~(?<![a-z0-9])(otp|totp|mfa|2fa)([_-]?codes?)?(?![a-z0-9])~i',
		'~(?<![a-z])auth(?![a-z])~i',
	];
	
	public function boot(Application $app): void
	{
		parent::boot($app);
		$app->getServices()->events->addWriter($this, static::SYMBOL);
	}
	
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
		$this->write($throwable, $extra);
		
		return $this;
	}
	
	/**
	 * Writer: append the formatted event — with the request/CLI context and
	 * redacted request variables — to today's log file.
	 */
	public function write(
		Throwable $event,
		array $extra,
	): void
	{
		$output = $this->getEvent($event);
		
		// extras
		foreach($extra as $key => $value)
		{
			$output.= $key . ': ' . $value . PHP_EOL;
		}
		// prepend
		$prepend = $this->getPrepend();
		// append
		$append = $this->getAppend();
		
		$output = $prepend . $output . $append . PHP_EOL;
		$this->output($this->removeText($output));
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
				$_SERVER['REQUEST_METHOD'] ?? '',
				$this->removeFromUrl($_SERVER['REQUEST_URI'] ?? ''),
				Client::getIp(),
				$_SERVER['HTTP_USER_AGENT'] ?? ''
			);
		}
		else if($this->app->isInterfaceCli())
		{
			$prepend.= 'CLI: ' . (isset($_SERVER['argv']) ?
				implode(' ', $this->removeFromArgs($_SERVER['argv'])) : 'no arguments');
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
				. json_encode($this->remove($_GET),
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
		
		return $append;
	}
	
	public function remove(array $data): array
	{
		foreach($data as $key => $value)
		{
			if(is_string($key) && $this->isSecretName($key))
			{
				$data[$key] = '[redacted]';
			}
			elseif(is_array($value))
			{
				$data[$key] = $this->remove($value);
			}
		}
		
		return $data;
	}
	
	public function removeFromUrl(string $url): string
	{
		$position = strpos($url, '?');
		if($position === false)
		{
			return $this->removeFromPath($url);
		}
		
		$query = (string)preg_replace_callback(
			'~(^|&)([^&=]+)=([^&#]*)~',
			fn(array $match): string => $this->isSecretName(rawurldecode($match[2]))
				? $match[1] . $match[2] . '=[redacted]'
				: $match[0],
			substr($url, $position + 1),
		);

		return $this->removeFromPath(substr($url, 0, $position)) . '?' . $query;
	}
	
	/** Redact the value following a secret name, such as /token/value. */
	public function removeFromPath(string $path): string
	{
		$segments = explode('/', $path);
		$last = count($segments) - 1;
		for($index = 0; $index < $last; $index++)
		{
			if($this->isSecretName(rawurldecode($segments[$index])))
			{
				$segments[++$index] = '[redacted]';
			}
		}
		
		return implode('/', $segments);
	}
	
	public function removeFromArgs(
		array $args,
	): array
	{
		$removeNext = false;
		
		foreach($args as $key => $arg)
		{
			if(is_string($arg) === false)
			{
				continue;
			}
			
			if($removeNext)
			{
				$args[$key] = '[redacted]';
				$removeNext = false;
				
				continue;
			}
			
			if(preg_match('~^(--?)?([^=]+)=(.*)$~s', $arg, $match) === 1)
			{
				$args[$key] = $this->isSecretName($match[2])
					? $match[1] . $match[2] . '=[redacted]'
					: $arg;
				
				continue;
			}
			
			if($this->isSecretName(ltrim($arg, '-')))
			{
				// the name stays, the value that follows is dropped
				$removeNext = true;
				
				continue;
			}
			
		}
		
		return $args;
	}
	
	public function isSecretName(string $name): bool
	{
		foreach($this->remove as $pattern)
		{
			if(preg_match($pattern, $name) === 1)
			{
				return true;
			}
		}
		
		return false;
	}
	
	public function removeText(
		string $text,
	): string
	{
		if($text === '')
		{
			return $text;
		}
		
		$name = '(["\']?)([A-Za-z0-9_.\-\[\]]+)\1';
		// A colon in PHP's :: operator is not a key/value separator.
		$separator = '(\s*(?:=>|:(?!:)|=)\s*)';
		$scheme ='(?:(?:bearer|basic|token|digest)\s+)?';
		
		// Quoted values.
		$text = $this->removePairs($text,
			'~' . $name . $separator . '(["\'])' . $scheme
				. '((?:\\\\.|(?!\4)[^\\\\])*)\4~i');
		
		// Escaped quotes inside a JSON string.
		$text = $this->removePairs($text,
			'~' . $name . $separator . '(\\\\["\'])' . $scheme
				. '((?:(?!\4).)*)\4~i');
		
		// Unquoted values.
		$text = $this->removePairs($text,
			'~' . $name . $separator . '()' . $scheme
				. '([^"\'\s,;)&}>\\\\]{1,512})~i');
		
		// XML element values.
		$text = (string)preg_replace_callback(
			'~<([A-Za-z0-9_.:\-]+)((?:\s[^>]*)?)>([^<]*)</\1\s*>~i',
			fn(array $match): string => $this->isSecretName($match[1])
				? '<' . $match[1] . $match[2] . '>[redacted]</' . $match[1] . '>'
				: $match[0],
			$text,
		);
		
		return $text;
	}
	
	protected function removePairs(
		string $text,
		string $pattern,
	): string
	{
		return (string)preg_replace_callback(
			$pattern,
			fn(array $match): string => $match[5] === '' || $this->isSecretName($match[2]) === false
				? $match[0]
				: $match[1] . $match[2] . $match[1]
					. $match[3] . $match[4] . '[redacted]' . $match[4],
			$text,
		);
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
					. PHP_EOL . $event->getTraceAsString() . PHP_EOL;
				
				$previous = true;
			}
			while($event = $event->getPrevious());
		}
		
		return $output;
	}
}
