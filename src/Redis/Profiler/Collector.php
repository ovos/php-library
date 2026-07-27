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
	 * Fallback command cap when profilers.redis.limit is not configured;
	 * 0 stays available as an explicit "no limit" escape hatch
	 */
	public const int LIMIT_DEFAULT = 500;
	
	/**
	 * Contains collected data
	 */
	protected SplQueue $commands;
	
	public static int $limit = 0;
	
	/**
	 * Pauses collection while the profiler stream writes its own redis
	 * commands — they must not show up in the reports they feed
	 */
	public static bool $paused = false;
	
	/**
	 * Commands seen, including the ones the limit has already shifted out — the
	 * queue alone cannot say whether it is the whole story or the tail of it.
	 * Paused commands (the profiler's own writes) never count: they are not
	 * part of the request being profiled.
	 */
	protected int $total = 0;
	
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
		if(self::$paused === true)
		{
			return $this;
		}
		
		$this->commands->push([
			'command' => $command,
			'keys' => $keys,
			'args' => $args,
			'measurement' => $measurement,
		]);
		$this->total++;
		
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
	
	/**
	 * How many commands ran, retained or not
	 */
	public function getTotal(): int
	{
		return $this->total;
	}
}
