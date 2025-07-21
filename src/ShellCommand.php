<?php
declare(strict_types=1);

namespace Ovos;

use function count;
use function str_replace;
use function preg_replace;
use function exec;
use function sprintf;

/**
 * ShellCommand
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
class ShellCommand
{
	/**
	 * @var string
	 */
	protected string $_command;
	
	/**
	 * @var string
	 */
	protected string $_executedCommand;
	
	/**
	 * @var ?array
	 */
	protected ?array $_output = null;
	
	/**
	 * @var int
	 */
	protected int $_exitStatus;
	
	/**
	 * @param string|Command $command
	 */
	public function __construct(string|Command $command)
	{
		$this->setCommand($command);
	}
	
	/**
	 * @param string|Command $command
	 */
	public function setCommand(string|Command $command): void
	{
		if($command instanceof Command)
		{
			$command = $command->__toString();
		}
		
		// remove newlines and convert single quotes to double to prevent errors
		$command = str_replace(["\n", "\r", "'"], ['', '', '"'], $command);
		// replace multiple spaces with a single space
		$command = preg_replace('~(\s){2,}~', ' ', $command);
		
		$this->_command = $command;
	}
	
	/**
	 * @return string
	 */
	public function getCommand(): string
	{
		return $this->_command;
	}
	
	/**
	 * @return string
	 */
	public function getExecutedCommand(): string
	{
		return $this->_executedCommand;
	}
	
	/**
	 * Executes a shell command with given arguments
	 *
	 * @param mixed ...$args
	 *
	 * @return bool
	 */
	public function execute(...$args): bool
	{
		$command = $this->getCommand();
		
		if(count($args))
		{
			$command = sprintf($command, ...$args);
		}
		
		// execute the command
		$this->_exitStatus = 0; // 0: success, 1: error
		exec($command, $this->_output, $this->_exitStatus);
		$this->_executedCommand = $command;
		
		return !$this->_exitStatus;
	}
	
	/**
	 * @return int
	 */
	public function getExitStatus(): int
	{
		return $this->_exitStatus;
	}
	
	/**
	 * Returns command output
	 *
	 * @return array
	 */
	public function getOutput(): array
	{
		return $this->_output;
	}
}
