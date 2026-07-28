<?php
declare(strict_types=1);

namespace Ovos\Pdo\Profiler;

use Ovos\ArrayObject;
use SplQueue;

use function count;
use function array_walk;
use function is_numeric;
use function str_replace;
use function substr_replace;
use function strpos;
use function strlen;
use function preg_replace;
use function trim;

/**
 * Reporter
 * @url https://github.com/spiritix/pdo-profiler
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Reporter
{
	/**
	 * Contains data taken from a collector
	 */
	protected SplQueue $queries;
	
	/**
	 * Stores collector data into internal array
	 */
	public function __construct()
	{
		$this->queries = Collector::getInstance()
			->getQueries();
	}
	
	public function getCount(): int
	{
		return count($this->queries);
	}
	
	/**
	 * How many queries ran, including those the display limit dropped
	 */
	public function getTotal(): int
	{
		return Collector::getInstance()->getTotal();
	}
	
	/**
	 * Builds a nice and readable array report
	 *
	 * @return ?ArrayObject[]
	 */
	public function getReport(): ?array
	{
		if(empty($this->queries))
		{
			return null;
		}
		
		$report = [];
		foreach($this->queries as $key => $query)
		{
			$report[] = new ArrayObject(
			[
				'sql' => $this->parseSql($query['sql'], $query['parameters']),
				'parameters' => $query['parameters'],
				'time' => $query['measurement']->getTotalTime(),
				'memory' => $query['measurement']->getTotalMemory(),
			]);
		}
		
		return $report;
	}
	
	/**
	 * Parse and beautify SQL query
	 */
	protected function parseSql(
		string $sql,
		array $parameters,
	): string
	{
		if(!empty($parameters))
		{
			// quote the values, doubling any quote inside them the way SQL does.
			// Without it a value like O'Brien closes its own literal, and every
			// literal after it in the statement is off by one — the rendered
			// query stops being something you could paste back into a client,
			// and anything reading it as SQL (the highlighter, an eye) is misled
			array_walk($parameters, static function(&$value)
			{
				if(null === $value)
				{
					$value = 'NULL';
					return;
				}
				$value = "'" . str_replace("'", "''", (string)$value) . "'";
			});
			
			// replace the values
			foreach($parameters as $parameter => $value)
			{
				$token = is_numeric($parameter)
					? '?'
					: ':' . $parameter;
				$tokenPosition = strpos($sql, $token);
				if($tokenPosition !== false)
				{
					$sql = substr_replace($sql,
						$value,
						$tokenPosition,
						strlen($token),
					);
				}
			}
		}
		
		// make it a one-liner
		return preg_replace('/\s+/', ' ', trim($sql));
	}
}
