<?php
declare(strict_types=1);

namespace Ovos;

use function count;
use function exec;
use function preg_replace;
use function sprintf;
use function str_replace;

/**
 * ShellCommand
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class ShellCommand
{
	protected string $command;
	
	protected string $executedCommand;
	
	protected ?array $output = null;
	
	protected int $exitStatus;
	
	public function __construct(
		string|Command $command,
	)
	{
		$this->setCommand($command);
	}
	
	public function setCommand(
		string|Command $command,
	): void
	{
		if($command instanceof Command)
		{
			$command = $command->__toString();
		}
		
		// remove newlines and convert single quotes to double to prevent errors
		$command = str_replace(["\n", "\r", "'"], ['', '', '"'], $command);
		// replace multiple spaces with a single space
		$command = preg_replace('~(\s){2,}~', ' ', $command);
		
		$this->command = $command;
	}
	
	public function getCommand(): string
	{
		return $this->command;
	}
	
	public function getExecutedCommand(): string
	{
		return $this->executedCommand;
	}
	
	/**
	 * Executes a shell command with given arguments
	 */
	public function execute(
		...$args,
	): bool
	{
		$command = $this->getCommand();
		
		if(count($args))
		{
			$command = sprintf($command, ...$args);
		}
		
		// execute the command
		$this->exitStatus = 0; // 0: success, 1: error
		exec($command, $this->output, $this->exitStatus);
		$this->executedCommand = $command;
		
		return !$this->exitStatus;
	}
	
	public function getExitStatus(): int
	{
		return $this->exitStatus;
	}
	
	/**
	 * Returns command output
	 */
	public function getOutput(): array
	{
		return $this->output;
	}
}
