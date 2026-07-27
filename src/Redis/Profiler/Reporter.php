<?php
declare(strict_types=1);

namespace Ovos\Redis\Profiler;

use Ovos\ArrayObject;
use SplQueue;

use function count;
use function gettype;
use function implode;
use function is_scalar;
use function mb_strimwidth;
use function trim;

/**
 * Reporter
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Reporter
{
	/**
	 * Contains data taken from a collector
	 */
	protected SplQueue $commands;
	
	public function __construct()
	{
		$this->commands = Collector::getInstance()
			->getCommands();
	}
	
	public function getCount(): int
	{
		return count($this->commands);
	}
	
	/**
	 * How many commands ran, including those the display limit dropped
	 */
	public function getTotal(): int
	{
		return Collector::getInstance()->getTotal();
	}
	
	/**
	 * Builds a readable array report
	 *
	 * @return ?ArrayObject[]
	 */
	public function getReport(): ?array
	{
		if(count($this->commands) === 0)
		{
			return null;
		}
		
		$report = [];
		foreach($this->commands as $command)
		{
			$report[] = new ArrayObject(
			[
				'call' => $this->formatCall(
					$command['command'],
					$command['keys'],
					$command['args'],
				),
				'time' => $command['measurement']->getTotalTime(),
				'memory' => $command['measurement']->getTotalMemory(),
			]);
		}
		
		return $report;
	}
	
	/**
	 * Render the call as a single redis-cli-like line: command, then keys,
	 * then args (values can be large/binary, so they are clipped).
	 */
	protected function formatCall(
		string $command,
		array $keys,
		array $args,
	): string
	{
		$parts = [$command];
		
		foreach($keys as $key)
		{
			$parts[] = (string)$key;
		}
		
		foreach($args as $arg)
		{
			$parts[] = is_scalar($arg)
				? mb_strimwidth((string)$arg, 0, 80, '…')
				: gettype($arg);
		}
		
		return trim(implode(' ', $parts));
	}
}
