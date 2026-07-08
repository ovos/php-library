<?php
declare(strict_types=1);

namespace Ovos\Test;

use function is_resource;
use function proc_close;
use function proc_open;

/**
 * Parallel
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Parallel
{
	/**
	 * Spawns a command WITHOUT waiting for it - returns the process handle
	 * to later hand to close(), or null when it could not start; the child
	 * inherits the parent's stdio (no pipes are set up)
	 *
	 * @return resource|null
	 */
	public static function spawn(
		string $command,
	): mixed
	{
		$process = proc_open($command, [], $pipes);
		
		return is_resource($process) === true
			? $process
			: null;
	}
	
	/**
	 * Waits for a spawned process to finish and closes its handle - a no-op
	 * for a null handle, so it pairs safely with spawn()
	 *
	 * @param resource|null $process
	 */
	public static function close(
		mixed $process,
	): void
	{
		if(is_resource($process) === true)
		{
			proc_close($process);
		}
	}
	
	/**
	 * Runs the command in "amount" parallel processes and waits for all of
	 * them to finish
	 */
	public static function run(
		string $command,
		int $amount,
	): void
	{
		$processes = [];
		for($i = 0; $i < $amount; $i++)
		{
			if(($process = self::spawn($command)) !== null)
			{
				$processes[] = $process;
			}
		}
		
		foreach($processes as $process)
		{
			self::close($process);
		}
	}
}
