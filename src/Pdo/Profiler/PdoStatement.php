<?php

namespace Ovos\Pdo\Profiler;

use Ovos\Pdo\Profiler\Exception\ProfilerException;
use Ovos\Measurement;
use PDO;
use PDOException;

use function ltrim;

/**
 * PdoStatement
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
class PdoStatement extends \PDOStatement
{
	/**
	 * Contains all bound parameters
	 *
	 * @var array
	 */
	protected array $_parameters = [];
	
	/**
	 * Catches parameter value, passes arguments to PDO
	 * @see https://www.php.net/manual/en/pdostatement.bindparam.php
	 * @inheritDoc
	 */
	public function bindParam(string|int $param,
		mixed &$var,
		int $type = PDO::PARAM_STR,
		int $maxLength = 0,
		mixed $driverOptions = null
	): bool
	{
		$this->_storeParameter($param, $var);
		
		return parent::bindParam($param, $var, $type, $maxLength, $driverOptions);
	}
	
	/**
	 * Catches value, passes arguments to PDO
	 * @see https://www.php.net/manual/en/pdostatement.bindvalue.php
	 * @inheritDoc
	 */
	public function bindValue(string|int $param,
		mixed $value,
		int $type = PDO::PARAM_STR
	): bool
	{
		$this->_storeParameter($param, $value);
		
		return parent::bindValue($param, $value, $type);
	}
	
	/**
	 * Measures time while executing statement, returns result
	 * @see https://www.php.net/manual/en/pdostatement.execute.php
	 * @inheritDoc
	 */
	public function execute(?array $params = null): bool
	{
		if(empty($this->queryString))
		{
			throw new ProfilerException('Whoops, looks like an empty query got executed');
		}
		
		if($params !== null)
		{
			foreach($params as $parameter => $value)
			{
				$this->_storeParameter($parameter, $value);
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
				->setQuery($this->queryString, $this->_parameters, $measurement);
			
			throw $exception;
		}
		
		$measurement->stop();
		
		// Pass query and parameters to collector
		Collector::getInstance()
			->setQuery($this->queryString, $this->_parameters, $measurement);
		
		// Reset values
		$this->_parameters = [];
		
		return $data;
	}

	/**
	 * @param string $name
	 * @param mixed $value
	 */
	protected function _storeParameter(string $name, mixed $value): void
	{
		$name = ltrim($name, ':');
		
		$this->_parameters[$name] = $value;
	}
}
