<?php

namespace Ovos;

use function count;

/**
 * Exception
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
class Exception extends \Exception
{
	/**
	 * Construct the exception.
	 *
	 * @param string|string[] ...$message [optional] The Exception message to throw.
	 */
	public function __construct(...$message)
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
}
