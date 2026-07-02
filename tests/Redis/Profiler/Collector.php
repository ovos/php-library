<?php
declare(strict_types=1);

namespace Tests\Redis\Profiler;

use Ovos\Measurement;
use Ovos\Redis\Profiler\Collector as ProfilerCollector;
use Ovos\Test;

/**
 * Collector — command-queue capping for the redis profiler.
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Collector extends Test
{
	public function capsQueueAtLimit(): bool
	{
		$limit = ProfilerCollector::$limit;
		
		try
		{
			ProfilerCollector::$limit = 3;
			$collector = new ProfilerCollector;
			
			for($i = 0; $i < 5; $i++)
			{
				$collector->setCommand('get', ['key' . $i], [], new Measurement);
			}
			
			return $collector->getCommands()->count() === 3;
		}
		finally
		{
			ProfilerCollector::$limit = $limit;
		}
	}
	
	/**
	 * The connection layer falls back to LIMIT_DEFAULT when
	 * profilers.redis.limit is unset — the collector itself treats 0 as
	 * "no limit", which must stay an explicit choice, never the default.
	 */
	public function defaultLimitIsBounded(): bool
	{
		return ProfilerCollector::LIMIT_DEFAULT > 0;
	}
}
