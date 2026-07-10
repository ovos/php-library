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
	
	public function getPriority(): ?int
	{
		return $this->priority;
	}
}
