<?php
declare(strict_types=1);

namespace Ovos\Controller\Traits;

/**
 * Trait Cli
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
trait Cli
{
	/**
	 * Process id
	 *
	 * @var int
	 */
	protected $_pid;

	/**
	 * @return int
	 */
	public function getPid(): int
	{
		if($this->_pid === null)
		{
			$this->_pid = getmypid();
		}

		return $this->_pid;
	}

	/**
	 * @return string|null
	 */
	public function readLine(): ?string
	{
		$line = stream_get_line(STDIN, 1024, PHP_EOL);

		if($line === '')
		{
			return null;
		}

		return $line;
	}

	/**
	 * Returns real memory usage in MB
	 *
	 * @return float
	 */
	protected function _getRealMemoryUsageMB(): float
	{
		return round(memory_get_usage(false) / (1024 * 1024), 2);
	}

	/**
	 * Log messages
	 *
	 * @param string ...$message,... params for sprintf
	 *
	 * @return void
	 */
	protected function _log(...$message)
	{
		if(\count($message))
		{
			$message = sprintf(...$message);
		}

		echo '[' . $this->getPid() . '] ' . date('Y-m-d H:i:s') . ': ' . $message . PHP_EOL;
		ob_flush();
	}
}
