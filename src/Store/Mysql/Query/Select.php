<?php
declare(strict_types=1);

namespace Ovos\Store\Mysql\Query;

use Ovos\Store\Mysql\Query;

use function implode;

/**
 * Select
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
class Select extends Query
{
	protected array $orderBy = [];
	
	protected array $groupBy = [];
	
	protected array $having = [];
	
	protected mixed $limit = null;
	
	protected mixed $offset = null;
	
	/**
	 * @return string
	 */
	public function getSql(): string
	{
		$sql = 'SELECT ' . implode(', ', $this->columns) . PHP_EOL;
		$sql.= 'FROM ' . $this->getFrom() . PHP_EOL;
		
		if($this->leftJoins !== [])
		{
			$sql.= 'LEFT JOIN '
				. implode(PHP_EOL . 'LEFT JOIN ', $this->leftJoins)
				. PHP_EOL;
		}
		
		if($this->innerJoins !== [])
		{
			$sql.= 'INNER JOIN '
				. implode(PHP_EOL . 'INNER JOIN ', $this->innerJoins)
				. PHP_EOL;
		}
		
		if($this->conditions !== [])
		{
			$sql.= 'WHERE '
				. $this->getConditionsSql($this->conditions)
				. PHP_EOL;
		}
		
		if($this->groupBy !== [])
		{
			$sql.= 'GROUP BY '
				. implode( ' , ', $this->groupBy)
				. PHP_EOL;
		}
		
		if($this->having !== [])
		{
			$sql.= 'HAVING '
				. implode(PHP_EOL . 'AND ', $this->having)
				. PHP_EOL;
		}
		
		if($this->orderBy !== [])
		{
			$sql.= 'ORDER BY '
				. implode( ' , ', $this->orderBy)
				. PHP_EOL;
		}
		
		if($this->limit !== null)
		{
			$sql.= 'LIMIT ' . $this->limit
				. PHP_EOL;
		}
		
		if($this->offset !== null)
		{
			$sql.= 'OFFSET ' . $this->offset
				. PHP_EOL;
		}
		
		return $sql;
	}
	
	public function select(
		string ...$fields,
	): static
	{
		foreach($fields as $field)
		{
			$this->columns[] = $field;
		}
		
		return $this;
	}
	
	public function from(
		string $table,
		?string $alias = null,
	): static
	{
		$this->table = $table;
		if($alias !== null)
		{
			$this->alias($alias);
		}
		
		return $this;
	}
	
	public function alias(
		string $alias,
	): static
	{
		$this->alias = $alias;
		
		return $this;
	}
	
	public function limit(
		mixed $limit,
	): static
	{
		$this->limit = $limit;
		
		return $this;
	}
	
	public function offset(
		mixed $offset,
	): static
	{
		$this->offset = $offset;
		
		return $this;
	}
	
	public function groupBy(
		string ...$arguments,
	): static
	{
		foreach($arguments as $argument)
		{
			$this->groupBy[] = $argument;
		}
		
		return $this;
	}
	
	public function having(
		string ...$conditions,
	): static
	{
		foreach($conditions as $condition)
		{
			$this->having[] = $condition;
		}
		
		return $this;
	}
	
	public function orderBy(
		string ...$arguments,
	): static
	{
		foreach($arguments as $argument)
		{
			$this->orderBy[] = $argument;
		}
		
		return $this;
	}
	
	public function innerJoin(
		string ...$joins,
	): static
	{
		$this->leftJoins = [];
		
		foreach($joins as $join)
		{
			$this->innerJoins[] = $join;
		}
		
		return $this;
	}
	
	public function leftJoin(
		string ...$joins,
	): static
	{
		$this->innerJoins = [];
		
		foreach($joins as $join)
		{
			$this->leftJoins[] = $join;
		}
		
		return $this;
	}
}
