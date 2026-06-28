<?php
declare(strict_types=1);

namespace Ovos\Redis\Profiler;

use Ovos\Measurement;
use Ovos\Singleton;
use SplQueue;

/**
 * Collector
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Collector
{
	use Singleton;
	
	/**
	 * Contains collected data
	 */
	protected SplQueue $commands;
	
	public static int $limit = 0;
	
	public function __construct()
	{
		$this->commands = new SplQueue;
	}
	
	/**
	 * Adds a redis command to collector
	 */
	public function setCommand(
		string $command,
		array $keys,
		array $args,
		Measurement $measurement,
	): static
	{
		$this->commands->push([
			'command' => $command,
			'keys' => $keys,
			'args' => $args,
			'measurement' => $measurement,
		]);
		
		// delete the oldest element from the queue if we reached the limit
		if(self::$limit && $this->commands->count() > self::$limit)
		{
			$this->commands->shift();
		}
		
		return $this;
	}
	
	/**
	 * Returns collected data
	 */
	public function getCommands(): SplQueue
	{
		return $this->commands;
	}
}
