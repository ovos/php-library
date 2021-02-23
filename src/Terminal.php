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
	 * @param bool $markup
	 * 
	 * @return void
	 */
	public static function output(string $message, $markup = false): void
	{
		$isCli = PHP_SAPI === 'cli';
		
		$message = $isCli && $markup ?
			Formatter::handleMarkup($message)
			: Formatter::stripMarkup($message);
		
		if($isCli)
		{
			fwrite(STDOUT, $message);
		}
		else
		{
			 print($message);
		}
	}
}
