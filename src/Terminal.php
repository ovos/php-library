<?php
declare(strict_types=1);

namespace Ovos;

use Ovos\Terminal\Formatter;

/**
 * Terminal
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
class Terminal
{
	/**
	 * @return bool
	 */
	public static function isTerminal(): bool
	{
		if(function_exists('posix_isatty') && posix_isatty(STDOUT))
		{
			return true;
		}
		
		if(getenv('TERM'))
		{
			return true;
		}
		
		return false;
	}

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
	 * @param bool $isCli
	 * 
	 * @return void
	 */
	public static function output(string $message, $isCli = true): void
	{
		$isTerminal = self::isTerminal();
		
		if($isCli === false || $isTerminal === false)
		{
			$message = self::stripMarkup($message);
		}
		else
		{
			foreach(Formatter::$colors as $color => $replace)
			{
				$message = str_replace("<$color>", $isTerminal ? $replace : '', $message);
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
