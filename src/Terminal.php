<?php
declare(strict_types=1);

namespace Ovos;

use Ovos\Terminal\Formatter;

use function fwrite;
use function stream_get_line;

/**
 * Terminal
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
class Terminal
{
	public const string SAPI_CLI = 'cli';
	
	public static function readLine(): ?string
	{
		$line = stream_get_line(STDIN, 1024, PHP_EOL);
		
		if($line === false || $line === '')
		{
			return null;
		}
		
		return $line;
	}
	
	/**
	 * Output the message string
	 */
	public static function output(
		string $message,
		bool $markup = false,
	): void
	{
		$message = self::getMessage($message, $markup);
		
		if(PHP_SAPI === self::SAPI_CLI)
		{
			// echo before the output buffer is sent, used by readLine
			fwrite(STDOUT, $message);
		}
		else
		{
			print($message);
		}
	}
	
	/**
	 * Parses color markers and returns a formatted message
	 */
	public static function getMessage(
		string $message,
		bool $markup = false,
	): string
	{
		$message = PHP_SAPI === self::SAPI_CLI && $markup
			? Formatter::handleMarkup($message)
			: Formatter::stripMarkup($message);
		
		return $message;
	}
}
