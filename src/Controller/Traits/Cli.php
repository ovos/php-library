<?php
declare(strict_types=1);

namespace Ovos\Controller\Traits;

use Ovos\Terminal;

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
	 * @var bool
	 */
	protected $_coloredOutput = false;

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
		return Terminal::readLine();
	}

	/**
	 * Returns memory usage in MB
	 *
	 * @return float
	 */
	public function getMemoryUsageMB(): float
	{
		return round(memory_get_usage(false) / (1024 * 1024), 2);
	}

	/**
	 * @param bool $coloredOutput
	 * 
	 * @return $this
	 */
	public function setColoredOutput(bool $coloredOutput): self
	{
		$this->_coloredOutput = $coloredOutput;
		
		return $this;
	}

	/**
	 * @return bool
	 */
	public function isColoredOutput(): bool
	{
		return $this->_coloredOutput;
	}

	/**
	 * Log messages
	 *
	 * @param string ...$message,... params for sprintf
	 *
	 * @return void
	 */
	public function log(...$message): void
	{
		if(\count($message))
		{
			$message = sprintf(...$message);
		}

		Terminal::output('<darkgray>[' . $this->getPid() . '] '
			. '<purple>' . date('Y-m-d H:i:s') . ': '
			. '<reset>' . $message . '<reset>' . PHP_EOL);
			
		ob_flush();
	}
}
