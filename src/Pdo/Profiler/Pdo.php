<?php

namespace Ovos\Pdo\Profiler;

use Ovos\Pdo\Profiler\Exception\ProfilerException;
use Ovos\Measurement;
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
	 * @param string $query
	 * @param ?int $fetchMode
	 * @param mixed ...$fetchModeArgs
	 *
	 * @return PDOStatement|false
	 *
	 * @throws ProfilerException
	 */
	public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs)
	{
		$args = func_get_args();

		// Execute query and measure time & memory usage
		$measurement = new Measurement;
		$measurement->start();
		
		try
		{
			$data = parent::query(...$args);
		}
		catch(PDOException $exception)
		{
			// log the query for debugging
			$measurement->stop();
			// pass query to collector
			Collector::getInstance()
				->setQuery($query, [], $measurement);

			throw $exception;
		}

		$measurement->stop();

		// Pass query  to collector
		Collector::getInstance()
			->setQuery($query, [], $measurement);

		return $data;
	}

	/**
	 * Measures time while executing query, returns number of affected rows
	 *
	 * @throws ProfilerException if query string is empty
	 * @throws PDOException on query failure
	 * @see PDO::query
	 *
	 * @param string $query
	 *
	 * @return int
	 *
	 * @throws ProfilerException
	 */
	public function exec(string $query): int
	{
		// Execute query and measure time & memory usage
		$measurement = new Measurement;
		$measurement->start();
		
		try
		{
			$affectedRows = parent::exec($query);
		}
		catch(PDOException $exception)
		{
			// log the query for debugging
			$measurement->stop();
			
			// pass query to collector
			Collector::getInstance()
				->setQuery($query, [], $measurement);

			throw $exception;
		}

		$measurement->stop();

		// Pass query  to collector
		Collector::getInstance()
			->setQuery($query, [], $measurement);

		return $affectedRows;
	}
}
