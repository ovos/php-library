<?php

namespace Ovos\Pdo\Profiler;

use Ovos\Measurement;
use PDOStatement;
use PDOException;

use function func_get_args;

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
	 * @see https://www.php.net/manual/en/pdo.query
	 * @inheritDoc
	 */
	public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): false|PDOStatement
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
	 * @see https://www.php.net/manual/en/pdo.exec.php
	 * @inheritDoc
	 */
	public function exec(string $statement): int|false
	{
		// Execute query and measure time & memory usage
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
			
			// pass query to collector
			Collector::getInstance()
				->setQuery($statement, [], $measurement);
			
			throw $exception;
		}
		
		$measurement->stop();
		
		// Pass query  to collector
		Collector::getInstance()
			->setQuery($statement, [], $measurement);
		
		return $affectedRows;
	}
}
