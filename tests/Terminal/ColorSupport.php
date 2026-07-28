<?php
declare(strict_types=1);

namespace Tests\Terminal;

use Ovos\Response;
use Ovos\Terminal;
use Ovos\Terminal as Subject;
use Ovos\Test;

use function getenv;
use function putenv;

/**
 * Stands in for the real Terminal with the tty probe pinned off, so the
 * environment rules can be tested from an interactive terminal too. Its own
 * memo keeps the pinning out of the app-wide one.
 */
class TerminalProbe extends Terminal
{
	protected static ?bool $supportsColor = null;
	
	public static function isInteractive(): bool
	{
		return false;
	}
}

/**
 * ColorSupport - when CLI output may carry ANSI color
 *
 * The interesting cases are both non-tty: a composer script (colors wanted,
 * since composer relays escapes to the terminal) and a cron job redirected into
 * a .txt (colors must stay out of the file).
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class ColorSupport extends Test
{
	/**
	 * The env this test rewrites, restored afterwards
	 */
	protected const array KEYS =
	[
		Subject::ENV_NO_COLOR,
		Subject::ENV_COLOR,
		Subject::ENV_COMPOSER,
		Subject::ENV_TERM,
	];
	
	public function noColorWins(): bool
	{
		return $this->resolve(
		[
			Subject::ENV_NO_COLOR => '1',
			// even against an explicit force and a composer-like environment
			Subject::ENV_COLOR => '1',
			Subject::ENV_COMPOSER => '/usr/bin/composer',
			Subject::ENV_TERM => 'xterm-256color',
		]) === false;
	}
	
	public function cliColorForcesBothWays(): bool
	{
		return $this->resolve([Subject::ENV_COLOR => '1']) === true
			&& $this->resolve([Subject::ENV_COLOR => 'yes']) === true
			&& $this->resolve(
			[
				Subject::ENV_COLOR => '0',
				Subject::ENV_COMPOSER => '/usr/bin/composer',
				Subject::ENV_TERM => 'xterm-256color',
			]) === false;
	}
	
	public function composerScriptOnATerminalGetsColor(): bool
	{
		// stdout is composer's pipe, so this is the case a plain isatty() check
		// would get wrong
		return $this->resolve(
		[
			Subject::ENV_COMPOSER => '/usr/bin/composer',
			Subject::ENV_TERM => 'xterm-256color',
		]) === true;
	}
	
	public function cronStaysPlain(): bool
	{
		// no tty, no TERM: output is very likely a log file
		return $this->resolve([]) === false
			// dumb terminals do not render escapes either
			&& $this->resolve(
			[
				Subject::ENV_COMPOSER => '/usr/bin/composer',
				Subject::ENV_TERM => 'dumb',
			]) === false
			// composer's env alone is not enough
			&& $this->resolve([Subject::ENV_COMPOSER => '/usr/bin/composer']) === false;
	}
	
	public function cliResponseFollowsDetectionAndCanOnlyVeto(): bool
	{
		// try/finally: this pins the app-wide memo, and a throw in between would
		// leave every later test running against the pinned answer
		try
		{
			Subject::setSupportsColor(true);
			$auto = (new Response\Cli)->getColoredOutput();
			
			// a response silences color for itself, permanently — there is no
			// un-veto, because a fresh response already defers to the environment
			$vetoed = (new Response\Cli)->disableColoredOutput()->getColoredOutput();
			
			// and where detection says no, a fresh response says no too: forcing
			// belongs to CLI_COLOR, which governs log lines and tables alike
			Subject::setSupportsColor(false);
			$denied = (new Response\Cli)->getColoredOutput();
		}
		finally
		{
			Subject::setSupportsColor(null);
		}
		
		return $auto === true
			&& $vetoed === false
			&& $denied === false;
	}
	
	/**
	 * Resolves color support against a given environment, with the memo and the
	 * previous env restored afterwards
	 */
	protected function resolve(
		array $env,
	): bool
	{
		$restore = [];
		foreach(self::KEYS as $key)
		{
			$restore[$key] = getenv($key);
			putenv($key . '=' . ($env[$key] ?? ''));
		}
		
		TerminalProbe::setSupportsColor(null);
		$supported = TerminalProbe::supportsColor();
		
		foreach($restore as $key => $value)
		{
			putenv($value === false
				? $key
				: $key . '=' . $value);
		}
		TerminalProbe::setSupportsColor(null);
		
		return $supported;
	}
}
