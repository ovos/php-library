<?php
declare(strict_types=1);

namespace Ovos\Pdo\Profiler;

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
	 * @var null|self
	 */
	protected static $instance;

	/**
	 * Contains collected data
	 *
	 * @var array
	 */
	protected $_queries = [];

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
	 * Adds a query to collector
	 *
	 * @param string $sql SQL statement
	 * @param array $parameters Statement values
	 * @param array $start Execution start time and memory usage
	 * @param array $end Execution end time and memory usage
	 *
	 * @return self
	 */
	public function setQuery(string $sql, array $parameters, array $start, array $end)
	{
		$this->_queries[] = array
		(
			'sql' => $sql,
			'parameters' => $parameters,
			'start' => $start,
			'end' => $end
		);

		return $this;
	}

	/**
	 * Returns collected data
	 *
	 * @return array
	 */
	public function getQueries(): array
	{
		return $this->_queries;
	}
}