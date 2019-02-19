<?php
declare(strict_types=1);

namespace Ovos;

use Ovos\Terminal\Formatter;
use function Ovos\app;

/**
 * Terminal
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
class Terminal
{
	/**
	 * @return string|null
	 */
	public static function readLine(): ?string
	{
		$line = stream_get_line(STDIN, 1024, PHP_EOL);

		if($line === '')
		{
			return null;
		}

		return $line;
	}

	/**
	 * Parsers color markers inside of CLI messages
	 * 
	 * @param string $message
	 * 
	 * @return void
	 */
	public static function output(string $message): void
	{
		$isCli = PHP_SAPI === 'cli';
		
		if($isCli === false)
		{
			$message = self::stripMarkup($message);
		}
		else
		{
			foreach(Formatter::$colors as $color => $replace)
			{
				$message = str_replace("<$color>", $replace, $message);
			}
		}
		
		$isCli ? fwrite(STDOUT, $message) : print($message);
	}
	
	/**
	 * @param string $message
	 * 
	 * @return string
	 */
	public static function stripMarkup(string $message): string
	{
		foreach(Formatter::$colors as $color => $replace)
		{
			$message = str_replace("<$color>", '', $message);
		}
		
		return $message;
	}
}
