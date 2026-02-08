<?php
declare(strict_types=1);

namespace Ovos\Test;

use function proc_open;
use function proc_get_status;
use function proc_close;
use function is_resource;
use function usleep;

/**
 * Parallel
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Parallel
{
	public static function run(
		string $command,
		int $amount,
	): void
	{
		$processes = [];
		for($i = 0; $i < $amount; $i++)
		{
			$process = proc_open($command, [], $pipes[]);
			if(is_resource($process))
			{
				$processes[] = $process;
			}
		}
		
		// wait for all processes to finish
		$running = true;
		while($running)
		{
			$running = false;
			foreach($processes as $process)
			{
				if(is_resource($process) === false)
				{
					continue;
				}
				
				$status = proc_get_status($process);
				if($status['running'])
				{
					$running = true;
					usleep(10000); // wait 10ms before checking again
				}
				else
				{
					proc_close($process);
				}
			}
		}
	}
}

