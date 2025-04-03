<?php
declare(strict_types=1);

namespace Ovos;

use Ovos\Terminal\Formatter;

use function stream_get_line;
use function fwrite;

/**
 * Terminal
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
class Terminal
{
	/**
	 * @var string
	 */
	public const string SAPI_CLI = 'cli';
	
	/**
	 * @return ?string
	 */
	public static function readLine(): null|string
	{
		$line = stream_get_line(STDIN, 1024, PHP_EOL);
		
		if($line === '')
		{
			return null;
		}
		
		return $line;
	}
	
	/**
	 * Output the message string
	 * 
	 * @param string $message
	 * @param bool $markup
	 * 
	 * @return void
	 */
	public static function output(string $message, bool $markup = false): void
	{
		$message = self::getMessage($message, $markup);
		
		if(PHP_SAPI === self::SAPI_CLI)
		{
			// echo before output buffer is sent, used by readLine
			fwrite(STDOUT, $message);
		}
		else
		{
			print($message);
		}
	}
	
	/**
	 * Parses color markers and returns formatted message
	 * 
	 * @param string $message
	 * @param bool $markup
	 * 
	 * @return string
	 */
	public static function getMessage(string $message, bool $markup = false): string
	{
		$message = PHP_SAPI === self::SAPI_CLI && $markup
			? Formatter::handleMarkup($message)
			: Formatter::stripMarkup($message);
		
		return $message;
	}
}
