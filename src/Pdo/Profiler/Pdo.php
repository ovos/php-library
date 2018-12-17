<?php

namespace Ovos\Pdo\Profiler;
use Ovos\Pdo\Profiler\Exception\ProfilerException;
use PDOStatement;
use PDOException;

/**
 * Pdo
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
class Pdo extends \PDO
{
	/**
	 * Measures time while executing query, returns result
	 *
	 * @throws ProfilerException if query string is empty
	 * @throws PDOException on query failure
	 * @see PDO::query
	 *
	 * @param string $queryString
	 *
	 * @return PDOStatement|false
	 *
	 * @throws ProfilerException
	 */
	public function query($queryString)
	{
		$args = func_get_args();

		// Execute query and measure time & memory usage
		$start = [microtime(true), memory_get_usage(false)];
		try
		{
			$data = call_user_func_array('parent::query', $args);
		}
		catch(PDOException $e)
		{
			// log the query for debugging
			$end = [microtime(true), memory_get_usage(false)];
			// pass query to collector
			Collector::getInstance()
				->setQuery($queryString, array(), $start, $end);

			throw $e;
		}

		$end = [microtime(true), memory_get_usage(false)];

		// Pass query  to collector
		Collector::getInstance()
			->setQuery($queryString, array(), $start, $end);

		return $data;
	}

	/**
	 * Measures time while executing query, returns number of affected rows
	 *
	 * @throws ProfilerException if query string is empty
	 * @throws PDOException on query failure
	 * @see PDO::query
	 *
	 * @param string $queryString
	 *
	 * @return int
	 *
	 * @throws ProfilerException
	 */
	public function exec($queryString): int
	{
		// Execute query and measure time & memory usage
		$start = [microtime(true), memory_get_usage(false)];
		try
		{
			$affectedRows = parent::exec($queryString);
		}
		catch(PDOException $e)
		{
			// log the query for debugging
			$end = [microtime(true), memory_get_usage(false)];
			// pass query to collector
			Collector::getInstance()
				->setQuery($queryString, array(), $start, $end);

			throw $e;
		}

		$end = [microtime(true), memory_get_usage(false)];

		// Pass query  to collector
		Collector::getInstance()
			->setQuery($queryString, array(), $start, $end);

		return $affectedRows;
	}
}