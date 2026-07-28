<?php
declare(strict_types=1);

namespace Ovos\Response;

use Ovos\Terminal;

/**
 * Cli
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Cli extends Html
{
	/**
	 * A veto over Terminal::supportsColor(), which decides per environment: a
	 * run piped into a cron log stays free of escape sequences while an
	 * interactive run and `composer prod:update` both get color.
	 *
	 * There is deliberately no per-response way to force color ON. It could only
	 * ever reach part of the output — a Table renders what it is handed, while
	 * every log line goes through Terminal::getMessage() — so the switch belongs
	 * where it governs all of them at once: CLI_COLOR, or setSupportsColor().
	 */
	protected bool $coloredOutputDisabled = false;
	
	public function __construct(
		?string $response = null,
	)
	{
		parent::__construct();
		
		$this->set($response);
	}
	
	/**
	 * Silences color for this response, whatever the environment allows — for
	 * output meant to be read by a program rather than a person.
	 *
	 * Replaces setColoredOutput(): that took a bool whose true branch had
	 * quietly become a no-op, and a setter that ignores half its argument is
	 * worse than one that no longer exists.
	 */
	public function disableColoredOutput(): static
	{
		$this->coloredOutputDisabled = true;
		
		return $this;
	}
	
	public function getColoredOutput(): bool
	{
		return $this->coloredOutputDisabled === false
			&& Terminal::supportsColor();
	}
}
