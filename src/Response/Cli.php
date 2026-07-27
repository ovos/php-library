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
	 * false silences color for this response (e.g. output meant to be parsed);
	 * null and true both leave the environment in charge. Forcing color on
	 * belongs to CLI_COLOR / Terminal::setSupportsColor(), so that one switch
	 * governs every output path.
	 */
	protected ?bool $coloredOutput = null;
	
	public function __construct(
		?string $response = null,
	)
	{
		parent::__construct();
		
		$this->set($response);
	}
	
	/**
	 * false silences color for this response; null or true defer to detection
	 */
	public function setColoredOutput(
		?bool $coloredOutput,
	): static
	{
		$this->coloredOutput = $coloredOutput;
		
		return $this;
	}
	
	public function getColoredOutput(): bool
	{
		return ($this->coloredOutput ?? true)
			&& Terminal::supportsColor();
	}
}
