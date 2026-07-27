<?php
declare(strict_types=1);

namespace Ovos;

use Ovos\Terminal\Formatter;

use function fwrite;
use function function_exists;
use function getenv;
use function in_array;
use function sapi_windows_vt100_support;
use function stream_get_line;
use function stream_isatty;
use function strtolower;

/**
 * Terminal
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Terminal
{
	public const string SAPI_CLI = 'cli';
	
	// the de-facto standard opt-out (any non-empty value) and our own override
	public const string ENV_NO_COLOR = 'NO_COLOR';
	public const string ENV_COLOR = 'CLI_COLOR';
	// composer exports this into every script it runs — see supportsColor()
	public const string ENV_COMPOSER = 'COMPOSER_BINARY';
	public const string ENV_TERM = 'TERM';
	
	/**
	 * Resolved once per process by supportsColor(), or pinned by
	 * setSupportsColor()
	 */
	protected static ?bool $supportsColor = null;
	
	/**
	 * Listeners receiving every output() message raw — before markup
	 * handling, <color> tags intact — e.g. the profiler streaming CLI
	 * output live. status() lines are transient by design and are not
	 * announced. A listener contains its own failures; output() does
	 * not guard.
	 *
	 * @var callable[]
	 */
	protected static array $listeners = [];
	
	/**
	 * Registers an output listener
	 */
	public static function listen(
		callable $listener,
	): void
	{
		self::$listeners[] = $listener;
	}
	
	/**
	 * Whether this process may emit ANSI color.
	 *
	 * A plain isatty() check is not enough: composer relays script output
	 * through its own IO layer, so STDOUT is a pipe inside `composer
	 * prod:update` — the very place the colored profiler tables are wanted —
	 * while cron redirecting into a .txt is *also* not a tty and must stay
	 * clean. Composer exports COMPOSER_BINARY into its scripts and cron
	 * carries no TERM, which separates the two.
	 */
	public static function supportsColor(): bool
	{
		if(static::$supportsColor !== null)
		{
			return static::$supportsColor;
		}
		
		return static::$supportsColor = static::resolveColorSupport();
	}
	
	/**
	 * Pins the decision, bypassing detection; null restores it
	 */
	public static function setSupportsColor(
		?bool $supportsColor,
	): void
	{
		static::$supportsColor = $supportsColor;
	}
	
	protected static function resolveColorSupport(): bool
	{
		if(PHP_SAPI !== self::SAPI_CLI)
		{
			return false;
		}
		
		// https://no-color.org — any non-empty value disables color
		if((string)getenv(self::ENV_NO_COLOR) !== '')
		{
			return false;
		}
		
		// our own override, both ways
		$forced = self::getBoolEnv(self::ENV_COLOR);
		if($forced !== null)
		{
			return $forced;
		}
		
		if(static::isInteractive())
		{
			return true;
		}
		
		// a composer script (`@php cli.php …`): stdout is composer's pipe, but
		// composer passes escapes through untouched, so the decision belongs to
		// the shell that launched composer — TERM stands in for it
		$term = (string)getenv(self::ENV_TERM);
		
		return (string)getenv(self::ENV_COMPOSER) !== ''
			&& $term !== ''
			&& $term !== 'dumb';
	}
	
	/**
	 * Whether STDOUT is a terminal that renders escape sequences. On Windows
	 * the console needs VT processing switched on first, and the call reports
	 * whether that succeeded.
	 *
	 * The seam the color tests replace — a suite run from a real terminal would
	 * otherwise see a tty no matter which environment it sets up.
	 */
	protected static function isInteractive(): bool
	{
		if(stream_isatty(STDOUT) === false)
		{
			return false;
		}
		
		if(function_exists('sapi_windows_vt100_support'))
		{
			return sapi_windows_vt100_support(STDOUT, true);
		}
		
		return true;
	}
	
	/**
	 * Reads an env var as a tri-state flag: true, false, or unset/unparsable
	 */
	protected static function getBoolEnv(
		string $name,
	): ?bool
	{
		$value = strtolower((string)getenv($name));
		
		if(in_array($value, ['1', 'true', 'yes', 'on'], true))
		{
			return true;
		}
		
		if(in_array($value, ['0', 'false', 'no', 'off'], true))
		{
			return false;
		}
		
		return null;
	}
	
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
		foreach(self::$listeners as $listener)
		{
			$listener($message, $markup);
		}
		
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
	 * Output a transient status line: return to the start of the current
	 * line, write the message, then clear anything left from a previous,
	 * longer line - with no trailing newline, so the next status() or
	 * clearLine() overwrites it in place.
	 *
	 * A no-op unless something is watching: the \r and the erase sequence only
	 * mean anything to a terminal, and a cron log would collect them as junk.
	 * The line is transient by design, so nothing is lost by dropping it.
	 */
	public static function status(
		string $message,
		bool $markup = false,
	): void
	{
		if(self::supportsColor() === false)
		{
			return;
		}
		
		$message = self::getMessage($message, $markup);
		
		fwrite(STDOUT, "\r" . $message . "\33[K");
	}
	
	/**
	 * Erase the current line - used to clear the last status() before
	 * printing the final output. A no-op wherever status() is one, so the
	 * two stay in step.
	 */
	public static function clearLine(): void
	{
		if(self::supportsColor() === false)
		{
			return;
		}
		
		fwrite(STDOUT, "\r\33[K");
	}
	
	/**
	 * Parses color markers and returns a formatted message
	 */
	public static function getMessage(
		string $message,
		bool $markup = false,
	): string
	{
		// $markup declares that the string carries <color> tokens — whether
		// they resolve to ANSI or get stripped is the environment's call, so a
		// caller passing true cannot force escapes into a redirected log
		$message = $markup && self::supportsColor()
			? Formatter::handleMarkup($message)
			: Formatter::stripMarkup($message);
		
		return $message;
	}
}
