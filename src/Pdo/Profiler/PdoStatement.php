<?php

namespace Ovos\Pdo\Profiler;

use Ovos\Pdo\Profiler\Exception\ProfilerException;
use Ovos\Measurement;
use Override;
use PDO;
use PDOException;

use function ltrim;

/**
 * PdoStatement
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class PdoStatement extends \PDOStatement
{
	/**
	 * Contains all bound parameters
	 */
	protected array $parameters = [];
	
	/**
	 * Catches parameter value, passes arguments to PDO
	 * @see https://www.php.net/manual/en/pdostatement.bindparam.php
	 */
	#[Override]
	public function bindParam(
		string|int $param,
		mixed &$var,
		int $type = PDO::PARAM_STR,
		int $maxLength = 0,
		mixed $driverOptions = null,
	): bool
	{
		$this->storeParameter($param, $var);
		
		return parent::bindParam(
			$param,
			$var,
			$type,
			$maxLength,
			$driverOptions,
		);
	}
	
	/**
	 * Catches value, passes arguments to PDO
	 * @see https://www.php.net/manual/en/pdostatement.bindvalue.php
	 */
	#[Override]
	public function bindValue(
		string|int $param,
		mixed $value,
		int $type = PDO::PARAM_STR,
	): bool
	{
		$this->storeParameter($param, $value);
		
		return parent::bindValue($param, $value, $type);
	}
	
	/**
	 * Measures time while executing statement, returns result
	 * @see https://www.php.net/manual/en/pdostatement.execute.php
	 */
	#[Override]
	public function execute(
		?array $params = null,
	): bool
	{
		if(empty($this->queryString))
		{
			throw new ProfilerException(
				'Whoops, looks like an empty query got executed');
		}
		
		if($params !== null)
		{
			foreach($params as $parameter => $value)
			{
				$this->storeParameter($parameter, $value);
			}
		}

		// Execute query and measure time & memory usage
		$measurement = new Measurement;
		$measurement->start();
		
		try
		{
			$data = parent::execute($params);
		}
		catch(PDOException $exception)
		{
			// log the query for debugging
			$measurement->stop();
			// pass query and parameters to collector
			Collector::getInstance()
				->setQuery(
					$this->queryString,
					$this->parameters,
					$measurement,
				);
			
			throw $exception;
		}
		
		$measurement->stop();
		
		// Pass query and parameters to collector
		Collector::getInstance()
			->setQuery(
				$this->queryString,
				$this->parameters,
				$measurement,
			);
		
		// Reset values
		$this->parameters = [];
		
		return $data;
	}
	
	protected function storeParameter(
		string $name,
		mixed $value,
	): void
	{
		$name = ltrim($name, ':');
		
		$this->parameters[$name] = $value;
	}
}
