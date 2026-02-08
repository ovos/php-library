<?php

namespace Ovos\Pdo\Profiler;

use Ovos\Measurement;
use PDOStatement;
use PDOException;
use Override;

use function func_get_args;

/**
 * Pdo
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Pdo extends \PDO
{
	/**
	 * Measures time while executing a query, returns a result
	 * @see https://www.php.net/manual/en/pdo.query
	 */
	#[Override]
	public function query(
		string $query,
		?int $fetchMode = null,
		mixed ...$fetchModeArgs,
	): false|PDOStatement
	{
		$args = func_get_args();
		
		// execute the query and measure time & memory usage
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
			// pass the query to the collector
			Collector::getInstance()
				->setQuery($query, [], $measurement);
			
			throw $exception;
		}
		
		$measurement->stop();
		
		// pass the query to the collector
		Collector::getInstance()
			->setQuery($query, [], $measurement);
		
		return $data;
	}
	
	/**
	 * Measures time while executing a query, returns the number of affected rows
	 * @see https://www.php.net/manual/en/pdo.exec.php
	 */
	#[Override]
	public function exec(
		string $statement,
	): int|false
	{
		// execute the query and measure time & memory usage
		$measurement = new Measurement;
		$measurement->start();
		
		try
		{
			$affectedRows = parent::exec($statement);
		}
		catch(PDOException $exception)
		{
			// log the query for debugging
			$measurement->stop();
			
			// pass the query to the collector
			Collector::getInstance()
				->setQuery(
					$statement,
					[],
					$measurement,
				);
			
			throw $exception;
		}
		
		$measurement->stop();
		
		// pass the query to the collector
		Collector::getInstance()
			->setQuery(
				$statement,
				[],
				$measurement,
			);
		
		return $affectedRows;
	}
}
