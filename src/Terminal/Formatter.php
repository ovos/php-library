<?php
declare(strict_types=1);

namespace Ovos\Terminal;

use function array_key_exists;
use function array_keys;
use function htmlspecialchars;
use function implode;
use function preg_split;
use function str_pad;
use function str_replace;

use const ENT_QUOTES;
use const ENT_SUBSTITUTE;
use const PREG_SPLIT_DELIM_CAPTURE;
use const STR_PAD_BOTH;
use const PHP_EOL;

/**
 * Formatter
 *
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
	
	public static function getColor(
		string $color,
	): ?string
	{
		if(array_key_exists($color, self::$colors) === false)
		{
			return null;
		}
		
		return self::$colors[$color];
	}
	
	public static function handleMarkup(
		string $message,
	): string
	{
		foreach(self::$colors as $color => $replace)
		{
			$message = str_replace("<$color>",
				$replace,
				$message,
			);
		}
		
		return $message;
	}
	
	public static function stripMarkup(
		string $message,
	): string
	{
		foreach(self::$colors as $color => $replace)
		{
			$message = str_replace("<$color>",
				'',
				$message,
			);
		}
		
		return $message;
	}
	
	public static function stripTerminalMarkup(
		string $message,
	): string
	{
		foreach(self::$colors as $color => $replace)
		{
			$message = str_replace($replace,
				'',
				$message,
			);
		}
		
		return $message;
	}
	
	/**
	 * Resolves markup to HTML instead of ANSI: coloured runs become
	 * <span class="term-cyan">, which is the same class the profiler page's
	 * CLI pane renders, so one set of <color> tags serves terminal and browser.
	 *
	 * The content between tags is user data — a bound query value reaches this
	 * — so every segment is escaped individually. Never escape the whole string
	 * first: that would mangle the values the highlighter matched on. And note
	 * that Highlighter::sanitize() strips markup and raw ANSI, which is not the
	 * same job as escaping HTML.
	 */
	public static function handleMarkupHtml(
		string $message,
	): string
	{
		$pattern = '/<(' . implode('|', array_keys(self::$colors)) . ')>/';
		$parts = preg_split($pattern,
			$message,
			flags: PREG_SPLIT_DELIM_CAPTURE,
		);
		
		if($parts === false)
		{
			return self::escape($message);
		}
		
		$html = '';
		$color = self::COLOR_RESET;
		
		foreach($parts as $index => $part)
		{
			// odd offsets are the captured tag names, even ones the text between
			if($index % 2 === 1)
			{
				$color = $part;
				
				continue;
			}
			
			if($part === '')
			{
				continue;
			}
			
			$html .= $color === self::COLOR_RESET
				? self::escape($part)
				: '<span class="term-' . self::escape($color) . '">'
					. self::escape($part)
					. '</span>';
		}
		
		return $html;
	}
	
	protected static function escape(
		string $content,
	): string
	{
		return htmlspecialchars($content,
			ENT_QUOTES | ENT_SUBSTITUTE,
			'UTF-8',
		);
	}
	
	public static function getHeader(
		string $header,
	): string
	{
		return str_pad(' ' . $header . ' ',
			 50,
			 '-', STR_PAD_BOTH
		)
		. PHP_EOL;
	}
}
