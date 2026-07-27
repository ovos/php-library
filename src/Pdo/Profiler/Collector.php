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
	
	/**
	 * Queries seen, including the ones the limit has already shifted out — the
	 * queue alone cannot say whether it is the whole story or the tail of it
	 */
	protected int $total = 0;
	
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
		$this->total++;
		
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
	
	/**
	 * How many queries ran, retained or not
	 */
	public function getTotal(): int
	{
		return $this->total;
	}
}
