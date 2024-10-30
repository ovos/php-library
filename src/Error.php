<?php
declare(strict_types=1);

namespace Ovos;

use function array_slice;
use function explode;
use function implode;
use function preg_replace_callback;
use function sprintf;

use const E_ERROR;
use const E_WARNING;
use const E_PARSE;
use const E_NOTICE;
use const E_CORE_ERROR;
use const E_CORE_WARNING;
use const E_COMPILE_ERROR;
use const E_COMPILE_WARNING;
use const E_USER_ERROR;
use const E_USER_WARNING;
use const E_USER_NOTICE;
use const E_STRICT;
use const E_RECOVERABLE_ERROR;
use const E_DEPRECATED;
use const E_USER_DEPRECATED;

/**
 * Error
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
class Error
{
	/**
	 * @var int
	 */
	protected int $_errorCode;
	
	/**
	 * @var string
	 */
	protected string $_errorMessage;
	
	/**
	 * @var string
	 */
	protected string $_errorFile;
	
	/**
	 * @var int
	 */
	protected int $_errorLine;
	
	/**
	 * @var string
	 */
	protected string $_errorName;
	
	/**
	 * @var string
	 */
	protected string $_errorTrace;
	
	/**
	 * @param int $errorCode
	 * @param string $errorMessage
	 * @param string $errorFile
	 * @param int $errorLine
	 */
	public function __construct(
		int $errorCode,
		string $errorMessage,
		string $errorFile,
		int $errorLine
	)
	{
		$this->_errorCode = $errorCode;
		$this->_errorMessage = $errorMessage;
		$this->_errorFile = $errorFile;
		$this->_errorLine = $errorLine;
		$this->_errorName = $this->_getName();
		$this->_errorTrace = $this->_getTraceAsString();
	}
	
	/**
	 * Error code
	 *
	 * @return int
	 */
	public function getCode(): int
	{
		return $this->_errorCode;
	}
	
	/**
	 * Error message
	 *
	 * @return string
	 */
	public function getMessage(): string
	{
		return $this->_errorMessage;
	}
	
	/**
	 * Error file
	 *
	 * @return string
	 */
	public function getFile(): string
	{
		return $this->_errorFile;
	}
	
	/**
	 * Error line
	 *
	 * @return int
	 */
	public function getLine(): int
	{
		return $this->_errorLine;
	}
	
	/**
	 * Error name
	 *
	 * @return string
	 */
	public function getName(): string
	{
		return $this->_errorName;
	}
	
	/**
	 * Error trace
	 *
	 * @return string
	 */
	public function getTraceAsString(): string
	{
		return $this->_errorTrace;
	}
	
	/**
	 * Compose readable message
	 *
	 * @return string
	 */
	public function __toString(): string
	{
		return sprintf("error '%s' with message '%s' in %s:%s\nStack trace:\n%s",
			$this->_errorName, $this->_errorMessage, $this->_errorFile, $this->_errorLine, $this->_errorTrace);
	}
	
	/**
	 * Maps severity to cool name
	 *
	 * @return string
	 */
	protected function _getName(): string
	{
		$errorNames = array
		(
			E_ERROR	=> 'Error',
			E_WARNING => 'Warning',
			E_PARSE => 'Parsing Error',
			E_NOTICE => 'Notice',
			E_CORE_ERROR => 'Core Error',
			E_CORE_WARNING => 'Core Warning',
			E_COMPILE_ERROR => 'Compile Error',
			E_COMPILE_WARNING => 'Compile Warning',
			E_USER_ERROR => 'User Error',
			E_USER_WARNING => 'User Warning',
			E_USER_NOTICE => 'User Notice',
			E_STRICT  => 'Strict Notice',
			E_RECOVERABLE_ERROR => 'Catchable Fatal Error',
			E_DEPRECATED => 'Deprecated',
			E_USER_DEPRECATED => 'User Deprecated',
		);
		
		if(isset($errorNames[$this->_errorCode]))
		{
			return $errorNames[$this->_errorCode];
		}
		
		return 'Unknown Error';
	}
	
	/**
	 * Composes error trace
	 *
	 * @param int $tracesToIgnore
	 *
	 * @return string
	 */
	protected function _getTraceAsString(int $tracesToIgnore = 4): string
	{
		$exception = new Exception;
		$trace = $exception->getTraceAsString();
		$trace = preg_replace_callback('/((?:^|\n)#)(\d+)/', static function($r) use ($tracesToIgnore)
		{
			return $r[1] . ($r[2] - $tracesToIgnore);
		}, $trace);
		
		// Remove first 3 lines
		$trace = explode("\n", $trace);
		$trace = array_slice($trace, $tracesToIgnore);
		$trace = implode("\n", $trace);
		
		return $trace;
	}
}
