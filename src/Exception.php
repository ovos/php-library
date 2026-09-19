<?php

namespace Ovos;

use Ovos\Exception\HasPriority;

use function count;
use function sprintf;

/**
 * Exception
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Exception extends \Exception implements HasPriority
{
	/**
	 * Console/report priority (syslog 0-7); null defers to the default
	 * type-based mapping. Set via withPriority() at the throw site.
	 */
	protected ?int $priority = null;

	/**
	 * @param string|string[] ...$message [optional] The Exception message to throw.
	 */
	public function __construct(
		...$message,
	)
	{
		$count = count($message);
		if($count > 1)
		{
			parent::__construct(sprintf(...$message));
		}
		else if($count === 1)
		{
			parent::__construct($message[0]);
		}
		else
		{
			parent::__construct();
		}
	}
	
	/**
	 * Set the console/report priority (syslog 0-7) for this throwable.
	 */
	public function withPriority(
		int $priority,
	): static
	{
		$this->priority = $priority;
		
		return $this;
	}
	
	/**
	 * Move the throwable's origin to where it was RAISED rather than where it
	 * was constructed. A string logged through the Logger is wrapped in an
	 * Exception inside the library (Logger\Normalizer), so without this every
	 * logged message reports the wrapper's file and line — the same two for
	 * every message in every project, which is what an error console then
	 * groups by, links to, and reads the source snippet from. The TRACE is
	 * untouched: it always named the real caller.
	 */
	public function raisedAt(
		string $file,
		int $line,
	): static
	{
		if($file !== '')
		{
			$this->file = $file;
			$this->line = $line;
		}
		
		return $this;
	}
	
	public function getPriority(): ?int
	{
		return $this->priority;
	}
}
