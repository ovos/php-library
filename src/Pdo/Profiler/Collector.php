<?php
declare(strict_types=1);

namespace Ovos\Pdo\Profiler;

use Ovos\Measurement;
use SplQueue;
use function Ovos\config;

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
	 * @return self
	 */
	public static function getInstance(): self
	{
		if(self::$instance === null)
		{
			self::$instance = new self;
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
	 * @return self
	 */
	public function setQuery(string $sql, array $parameters, Measurement $measurement): self
	{
		$this->_queries->push([
			'sql' => $sql,
			'parameters' => $parameters,
			'measurement' => $measurement,
		]);
		
		// delete oldest element from the queue if we reached the limit
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
