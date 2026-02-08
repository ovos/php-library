<?php
declare(strict_types=1);

namespace Ovos\Pdo\Profiler;

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
	protected SplQueue $queries;
	
	public static int $limit = 0;
	
	public function __construct()
	{
		$this->queries = new SplQueue;
	}
	
	/**
	 * Adds a query to collector
	 */
	public function setQuery(
		string $sql,
		array $parameters,
		Measurement $measurement,
	): static
	{
		$this->queries->push([
			'sql' => $sql,
			'parameters' => $parameters,
			'measurement' => $measurement,
		]);
		
		// delete the oldest element from the queue if we reached the limit
		if(self::$limit && $this->queries->count() > self::$limit)
		{
			$this->queries->shift();
		}
		
		return $this;
	}
	
	/**
	 * Returns collected data
	 */
	public function getQueries(): SplQueue
	{
		return $this->queries;
	}
}
