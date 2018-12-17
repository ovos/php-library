<?php

namespace Ovos\Pdo\Profiler;
use Ovos\Pdo\Profiler\Exception\ProfilerException;
use PDOException;

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
	protected $_parameters = [];

	/**
	 * Catches parameter value, passes arguments to PDO
	 * @see PDOStatement::bindParam
	 *
	 * @param mixed $parameter
	 * @param mixed $variable
	 * @param int $dataType
	 * @param null $length
	 * @param null $driverOptions
	 *
	 * @return bool
	 */
	public function bindParam($parameter, &$variable, $dataType = PDO::PARAM_STR, $length = null, $driverOptions = null): bool
	{
		$this->_storeParameter($parameter, $variable);

		return parent::bindParam($parameter, $variable, $dataType, $length, $driverOptions);
	}

	/**
	 * Catches value, passes arguments to PDO
	 *
	 * @see PDOStatement::bindValue
	 *
	 * @param mixed $parameter
	 * @param mixed $value
	 * @param int $dataType
	 *
	 * @return bool
	 */
	public function bindValue($parameter, $value, $dataType = PDO::PARAM_STR): bool
	{
		$this->_storeParameter($parameter, $value);

		return parent::bindValue($parameter, $value, $dataType);
	}

	/**
	 * Measures time while executing statement, returns result
	 *
	 * @throws ProfilerException if query string is empty
	 * @throws PDOException on query failure
	 * @see PDOStatement::execute
	 *
	 * @param null $inputParameters
	 *
	 * @return bool
	 *
	 * @throws ProfilerException
	 */
	public function execute($inputParameters = null): bool
	{
		if(empty($this->queryString))
		{
			throw new ProfilerException('Whoops, looks like an empty query got executed');
		}

		if(!empty($inputParameters))
		{
			foreach($inputParameters as $parameter => $value)
			{
				$this->_storeParameter($parameter, $value);
			}
		}

		// Execute query and measure time & memory usage
		$start = [microtime(true), memory_get_usage(false)];
		try
		{
			$data = parent::execute($inputParameters);
		}
		catch(PDOException $e)
		{
			// log the query for debugging
			$end = [microtime(true), memory_get_usage(false)];
			// pass query and parameters to collector
			Collector::getInstance()
				->setQuery($this->queryString, $this->_parameters, $start, $end);

			throw $e;
		}

		$end = [microtime(true), memory_get_usage(false)];

		// Pass query and parameters to collector
		Collector::getInstance()
			->setQuery($this->queryString, $this->_parameters, $start, $end);

		// Reset values
		$this->_parameters = [];

		return $data;
	}

	/**
	 * @param string $name
	 * @param mixed $value
	 */
	protected function _storeParameter(string $name, $value)
	{
		$this->_parameters[$name] = $value;
	}
}