<?php

namespace Ovos;

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
	 * @param string[] ...$message [optional] The Exception message to throw.
	 */
	public function __construct(...$message)
	{
		if(\count($message) > 1)
		{
			parent::__construct(sprintf(...$message));
		}
		else
		{
			parent::__construct($message[0]);
		}
	}
}