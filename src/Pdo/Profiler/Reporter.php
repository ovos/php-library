<?php
declare(strict_types=1);

namespace Ovos\Pdo\Profiler;

use Ovos\Measurements;
use SplQueue;
use function count;
use function array_walk;
use function is_numeric;
use function substr_replace;
use function strpos;
use function strlen;
use function preg_replace;
use function trim;

/**
 * Reporter
 * @url https://github.com/spiritix/pdo-profiler
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
class Reporter
{
	/**
	 * Contains data taken from collector
	 *
	 * @var SplQueue
	 */
	protected SplQueue $_queries;

	/**
	 * Stores collector data into internal array
	 */
	public function __construct()
	{
		$this->_queries = Collector::getInstance()
			->getQueries();
	}

	/**
	 * @return int
	 */
	public function getCount(): int
	{
		return count($this->_queries);
	}
	
	/**
	 * Builds a nice and readable array report
	 *
	 * @return null|array
	 */
	public function getReport(): ?array
	{
		if(empty($this->_queries))
		{
			return null;
		}

		$report = [];
		foreach($this->_queries as $key => $query)
		{
			$report[] =
			[
				'sql' => $this->_parseSql($query['sql'], $query['parameters']),
				'parameters' => $query['parameters'],
				'time' => $query['measurement']->getTotalTime(),
				'memory' => $query['measurement']->getTotalMemory(),
			];
		}

		return $report;
	}

	/**
	 * Parse and beautify SQL query
	 *
	 * @param string $sql SQL statement
	 * @param array $parameters Statement parameters
	 *
	 * @return string
	 */
	protected function _parseSql(string $sql, array $parameters): string
	{
		if(!empty($parameters))
		{
			// Quote the values
			array_walk($parameters, function(&$value)
			{
				if(null === $value)
				{
					$value = 'NULL';
					return;
				}
				$value = "'" . $value . "'";
			});

			// Replace values
			foreach($parameters as $parameter => $value)
			{
				$token = is_numeric($parameter) ? '?' : ':' . $parameter;
				$tokenPosition = strpos($sql, $token);
				if($tokenPosition !== false)
				{
					$sql = substr_replace($sql, $value, $tokenPosition, strlen($token));
				}
			}
		}

		// make it a one-liner
		return preg_replace('/\s+/', ' ', trim($sql));
	}
}
