<?php
declare(strict_types=1);

namespace Ovos\Pdo\Profiler;

use Ovos\Measurement;

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
	protected static null|self $instance = null;

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
	 * @param Measurement $measurement time and memory usage
	 *
	 * @return self
	 */
	public function setQuery(string $sql, array $parameters, Measurement $measurement)
	{
		$this->_queries[] = array
		(
			'sql' => $sql,
			'parameters' => $parameters,
			'measurement' => $measurement,
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
