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
			$sql.= 'WHERE ' . implode(PHP_EOL . 'AND ', $this->_conditions) . PHP_EOL;
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
	 * @return self
	 */
	public function select(string ...$fields): self
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
	 * @return self
	 */
	public function from(string $table, ?string $alias = null): self
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
	 * @return self
	 */
	public function alias(string $alias): self
	{
		$this->_alias = $alias;
		
		return $this;
	}
	
	/**
	 * @param mixed $limit
	 *
	 * @return self
	 */
	public function limit(mixed $limit): self
	{
		$this->_limit = $limit;
		
		return $this;
	}
	
	/**
	 * @param mixed $offset
	 *
	 * @return self
	 */
	public function offset(mixed $offset): self
	{
		$this->_offset = $offset;
		
		return $this;
	}
	
	/**
	 * @param string ...$arguments
	 *
	 * @return self
	 */
	public function groupBy(string ...$arguments): self
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
	 * @return self
	 */
	public function having(string ...$conditions): self
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
	 * @return self
	 */
	public function orderBy(string ...$arguments): self
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
	 * @return self
	 */
	public function innerJoin(string ...$joins): self
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
	 * @return self
	 */
	public function leftJoin(string ...$joins): self
	{
		$this->_innerJoins = [];
		
		foreach($joins as $join)
		{
			$this->_leftJoins[] = $join;
		}
		
		return $this;
	}
}
