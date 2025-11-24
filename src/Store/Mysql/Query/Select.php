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
	/**
	 * @var ?string
	 */
	protected ?string $_alias = null;
	
	/**
	 * @var array
	 */
	protected array $_leftJoins = [];
	
	/**
	 * @var array
	 */
	protected array $_innerJoins = [];
	
	/**
	 * @var array
	 */
	protected array $_orderBy = [];
	
	/**
	 * @var array
	 */
	protected array $_groupBy = [];
	
	/**
	 * @var array
	 */
	protected array $_having = [];
	
	/**
	 * @var mixed
	 */
	protected mixed $_limit = null;
	
	/**
	 * @var mixed
	 */
	protected mixed $_offset = null;
	
	/**
	 * @return string
	 */
	public function getSql(): string
	{
		$sql = 'SELECT ' . implode(', ', $this->_columns) . PHP_EOL;
		$sql.= 'FROM ' . ($this->_alias === null
			? $this->_table
			: $this->_table . ' ' . $this->_alias
		) . PHP_EOL;
		
		if($this->_leftJoins !== [])
		{
			$sql.= 'LEFT JOIN ' . implode(PHP_EOL . 'LEFT JOIN ', $this->_leftJoins) . PHP_EOL;
		}
		
		if($this->_innerJoins !== [])
		{
			$sql.= 'INNER JOIN ' . implode(PHP_EOL . 'INNER JOIN ', $this->_innerJoins) . PHP_EOL;
		}
		
		if($this->_conditions !== [])
		{
			$sql.= 'WHERE ' . $this->_getConditionsSql($this->_conditions) . PHP_EOL;
		}
		
		if($this->_groupBy !== [])
		{
			$sql.= 'GROUP BY ' . implode( ' , ', $this->_groupBy) . PHP_EOL;
		}
		
		if($this->_having !== [])
		{
			$sql.= 'HAVING ' . implode(PHP_EOL . 'AND ', $this->_having) . PHP_EOL;
		}
		
		if($this->_orderBy !== [])
		{
			$sql.= 'ORDER BY ' . implode( ' , ', $this->_orderBy) . PHP_EOL;
		}
		
		if($this->_limit !== null)
		{
			$sql.= 'LIMIT ' . $this->_limit . PHP_EOL;
		}
		
		if($this->_offset !== null)
		{
			$sql.= 'OFFSET ' . $this->_offset . PHP_EOL;
		}
		
		return $sql;
	}
	
	/**
	 * @param string ...$fields
	 *
	 * @return static
	 */
	public function select(string ...$fields): static
	{
		foreach($fields as $field)
		{
			$this->_columns[] = $field;
		}
		
		return $this;
	}
	
	/**
	 * @param string $table
	 * @param ?string $alias
	 *
	 * @return static
	 */
	public function from(string $table, ?string $alias = null): static
	{
		$this->_table = $table;
		if($alias !== null)
		{
			$this->alias($alias);
		}
		
		return $this;
	}

	/**
	 * @param string $alias
	 *
	 * @return static
	 */
	public function alias(string $alias): static
	{
		$this->_alias = $alias;
		
		return $this;
	}
	
	/**
	 * @param mixed $limit
	 *
	 * @return static
	 */
	public function limit(mixed $limit): static
	{
		$this->_limit = $limit;
		
		return $this;
	}
	
	/**
	 * @param mixed $offset
	 *
	 * @return static
	 */
	public function offset(mixed $offset): static
	{
		$this->_offset = $offset;
		
		return $this;
	}
	
	/**
	 * @param string ...$arguments
	 *
	 * @return static
	 */
	public function groupBy(string ...$arguments): static
	{
		foreach($arguments as $argument)
		{
			$this->_groupBy[] = $argument;
		}
		
		return $this;
	}
	
	/**
	 * @param string ...$conditions
	 *
	 * @return static
	 */
	public function having(string ...$conditions): static
	{
		foreach($conditions as $condition)
		{
			$this->_having[] = $condition;
		}
		
		return $this;
	}
	
	/**
	 * @param string ...$arguments
	 *
	 * @return static
	 */
	public function orderBy(string ...$arguments): static
	{
		foreach($arguments as $argument)
		{
			$this->_orderBy[] = $argument;
		}
		
		return $this;
	}
	
	/**
	 * @param string ...$joins
	 *
	 * @return static
	 */
	public function innerJoin(string ...$joins): static
	{
		$this->_leftJoins = [];
		
		foreach($joins as $join)
		{
			$this->_innerJoins[] = $join;
		}
		
		return $this;
	}
	
	/**
	 * @param string ...$joins
	 *
	 * @return static
	 */
	public function leftJoin(string ...$joins): static
	{
		$this->_innerJoins = [];
		
		foreach($joins as $join)
		{
			$this->_leftJoins[] = $join;
		}
		
		return $this;
	}
}
