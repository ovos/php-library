<?php
declare(strict_types=1);

namespace Ovos\Pdo\Profiler;

use Ovos\Measurement;
use SplQueue;

/**
 * Collector
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
class Collector
{
	/**
	 * Collector instance
	 *
	 * @var ?self
	 */
	protected static ?self $instance = null;
	
	/**
	 * Contains collected data
	 *
	 * @var SplQueue
	 */
	protected SplQueue $_queries;
	
	/**
	 * @var int
	 */
	public static int $limit = 0;
	
	/**
	 * @return static
	 */
	public static function getInstance(): static
	{
		if(self::$instance === null)
		{
			self::$instance = new static;
		}
		
		return self::$instance;
	}
	
	/**
	 */
	public function __construct()
	{
		$this->_queries = new SplQueue;
	}
	
	/**
	 * Adds a query to collector
	 *
	 * @param string $sql SQL statement
	 * @param array $parameters Statement values
	 * @param Measurement $measurement time and memory usage
	 *
	 * @return static
	 */
	public function setQuery(string $sql, array $parameters, Measurement $measurement): static
	{
		$this->_queries->push([
			'sql' => $sql,
			'parameters' => $parameters,
			'measurement' => $measurement,
		]);
		
		// delete the oldest element from the queue if we reached the limit
		if(self::$limit && $this->_queries->count() > self::$limit)
		{
			$this->_queries->shift();
		}
		
		return $this;
	}
	
	/**
	 * Returns collected data
	 *
	 * @return SplQueue
	 */
	public function getQueries(): SplQueue
	{
		return $this->_queries;
	}
}
