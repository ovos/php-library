<?php
declare(strict_types=1);

namespace Ovos\Terminal;

use function array_key_exists;
use function str_pad;
use function str_replace;

use const STR_PAD_BOTH;
use const PHP_EOL;

/**
 * Formatter
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
class Formatter
{
	public const string COLOR_RESET = 'reset';
	public const string COLOR_BLACK = 'black';
	public const string COLOR_GRAY = 'gray';
	public const string COLOR_DARKGRAY = 'darkgray';
	public const string COLOR_BLUE = 'blue';
	public const string COLOR_DARKBLUE = 'darkblue';
	public const string COLOR_GREEN = 'green';
	public const string COLOR_DARKGREEN = 'darkgreen';
	public const string COLOR_CYAN = 'cyan';
	public const string COLOR_DARKCYAN = 'darkcyan';
	public const string COLOR_RED = 'red';
	public const string COLOR_DARKRED = 'darkred';
	public const string COLOR_PURPLE = 'purple';
	public const string COLOR_DARKPURPLE = 'darkpurple';
	public const string COLOR_BROWN = 'brown';
	public const string COLOR_YELLOW = 'yellow';
	public const string COLOR_WHITE = 'white';
	
	/**
	 * @var string[]
	 */
	public static array $colors =
	[
		self::COLOR_RESET => "\33[0m",
		self::COLOR_BLACK => "\33[0;30m",
		self::COLOR_GRAY => "\33[38;5;246m",
		self::COLOR_DARKGRAY => "\33[1;30m",
		self::COLOR_BLUE => "\33[1;34m",
		self::COLOR_DARKBLUE => "\33[0;34m",
		self::COLOR_GREEN => "\33[1;32m",
		self::COLOR_DARKGREEN => "\33[0;32m",
		self::COLOR_CYAN => "\33[1;36m",
		self::COLOR_DARKCYAN => "\33[0;36m",
		self::COLOR_RED => "\33[1;31m",
		self::COLOR_DARKRED => "\33[0;31m",
		self::COLOR_PURPLE => "\33[1;35m",
		self::COLOR_DARKPURPLE => "\33[0;35m",
		self::COLOR_BROWN => "\33[0;33m",
		self::COLOR_YELLOW => "\33[1;33m",
		self::COLOR_WHITE => "\33[1;37m",
	];
	
	/**
	 * @param string $color
	 * 
	 * @return ?string
	 */
	public static function getColor(string $color): ?string
	{
		if(array_key_exists($color, self::$colors) === false)
		{
			return null;
		}
		
		return self::$colors[$color];
	}
	
	/**
	 * @param string $message
	 * 
	 * @return string
	 */
	public static function handleMarkup(string $message): string
	{
		foreach(self::$colors as $color => $replace)
		{
			$message = str_replace("<$color>", $replace, $message);
		}
		
		return $message;
	}
	
	/**
	 * @param string $message
	 * 
	 * @return string
	 */
	public static function stripMarkup(string $message): string
	{
		foreach(self::$colors as $color => $replace)
		{
			$message = str_replace("<$color>", '', $message);
		}
		
		return $message;
	}
	
	/**
	 * @param string $message
	 * 
	 * @return string
	 */
	public static function stripTerminalMarkup(string $message): string
	{
		foreach(self::$colors as $color => $replace)
		{
			$message = str_replace($replace, '', $message);
		}
		
		return $message;
	}
	
	/**
	 * @param string $header
	 * 
	 * @return string
	 */
	public static function getHeader(string $header): string
	{
		return str_pad(' ' . $header . ' ',
			 50,
			 '-', STR_PAD_BOTH
		)
		. PHP_EOL;
	}
}
