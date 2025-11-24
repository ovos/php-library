<?php
declare(strict_types=1);

namespace Ovos\Store\Mysql;

use Ovos\Store\Mysql\Query\Condition;

use function count;
use function is_callable;
use function implode;

/**
 * Query
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
abstract class Query
{
	/**#@+
	 * Conditions
	 */
	public const string CONDITION_TYPE_DEFAULT = 'default';
	public const string CONDITION_TYPE_NESTED = 'nested';
	public const string CONDITION_OPERATOR_AND = 'AND';
	public const string CONDITION_OPERATOR_OR = 'OR';
	/**#@-*/
	
	/**
	 * @var string
	 */
	public const string INDENT = "\t";
	
	/**
	 * @var string
	 */
	protected string $_table;
	
	/**
	 * @var array
	 */
	protected array $_columns = [];
	
	 /**
	  * @var array
	  */
	 protected array $_conditions = [];
	
	 /**
	  * @var string
	  */
	 protected string $_conditionOperator = Condition::OPERATOR_AND;
	
	 /**
	  * @param string $table
	  */
	 public function __construct(string $table)
	 {
		$this->setTable($table);
	}
	
	/**
	 * @param string $table
	 *
	 * @return static
	 */
	public function setTable(string $table): static
	{
		$this->_table = $table;
		
		return $this;
	}
	
	/**
	 * @return string
	 */
	public function getTable(): string
	{
		return $this->_table;
	}
	
	/**
	 * @return string
	 */
	abstract public function getSql(): string;
	
	/**
	 * @return string
	 */
	public function __toString(): string
	{
		return $this->getSql();
	}
	
	/**
	 * @param string|callable ...$conditions
	 *
	 * @return static
	 */
	public function where(
		string|callable ...$conditions,
	): static
	{
		$count = count($conditions);
		if($count === 0)
		{
			return $this;
		}
		
		if(is_callable($conditions[0])
			&& $nestedCondition = $this->_getNestedCondition($conditions[0]))
		{
			$this->_conditions[] = $nestedCondition;
			
			return $this;
		}
		
		foreach($conditions as $condition)
		{
			$this->_conditions[] = $condition;
		}
		
		return $this;
	}
	
	/**
	 * @param callable $condition
	 * @param string $operator
	 *
	 * @return ?Condition
	 */
	protected function _getNestedCondition(
		callable $condition,
		string $operator = Condition::OPERATOR_AND,
	): ?Condition
	{
		$nestedQuery = clone $this;
		$nestedQuery->_conditions = [];
		$nestedQuery->_conditionOperator = Condition::OPERATOR_AND;
		
		$condition($nestedQuery);
		
		if(count($nestedQuery->_conditions) === 0)
		{
			return null;
		}
		
		return new Condition
		(
			Condition::TYPE_NESTED,
			$operator,
			nested: $nestedQuery->_conditions
		);
	}
	
	/**
	 * @param string|callable ...$conditions
	 *
	 * @return static
	 */
	public function andWhere(
		string|callable ...$conditions,
	): static
	{
		return $this->where(...$conditions);
	}
	
	/**
	 * @param string|callable ...$conditions
	 *
	 * @return static
	 */
	public function orWhere(
		string|callable ...$conditions,
	): static
	{
		$count = count($conditions);
		if($count === 0)
		{
			return $this;
		}
		
		if(is_callable($conditions[0])
			&& $nestedCondition= $this->_getNestedCondition($conditions[0],
			Condition::OPERATOR_OR))
		{
			$this->_conditions[] = $nestedCondition;
			
			return $this;
		}
		
		foreach($conditions as $condition)
		{
			$this->_conditions[] = new Condition
			(
				Condition::TYPE_DEFAULT,
				Condition::OPERATOR_OR,
				$condition,
			);
		}
		
		return $this;
	}
	
	/**
	 * @param string $field
	 * @param array $values
	 *
	 * @return static
	 */
	public function whereIn(string $field, array $values): static
	{
		if(count($values) === 0)
		{
			return $this;
		}
		
		$condition = $field
			. ' IN ('
			. implode(', ', $values)
			. ')';
		$this->_conditions[] = $condition;
		
		return $this;
	}
	
	/**
	 * @param string $field
	 * @param array $values
	 *
	 * @return static
	 */
	public function andWhereIn(string $field, array $values): static
	{
		return $this->whereIn($field, $values);
	}
	
	/**
	 * @param string $field
	 * @param array $values
	 *
	 * @return static
	 */
	public function whereNotIn(string $field, array $values): static
	{
		if(count($values) === 0)
		{
			return $this;
		}
		
		$condition = $field
			. ' NOT IN ('
			. implode(', ', $values) 
			. ')';
		
		$this->_conditions[] = $condition;
		
		return $this;
	}
	
	/**
	 * @param string $field
	 * @param array $values
	 *
	 * @return static
	 */
	public function andWhereNotIn(string $field, array $values): static
	{
		return $this->whereNotIn($field, $values);
	}
	
	/**
	 * @param string $field
	 * @param array $values
	 *
	 * @return static
	 */
	public function orWhereNotIn(string $field, array $values): static
	{
		if(count($values) === 0)
		{
			return $this;
		}
		
		$condition = $field
			. ' NOT IN ('
			. implode(', ', $values)
			. ')';
		$this->_conditions[] = new Condition
		(
			Condition::TYPE_DEFAULT,
			Condition::OPERATOR_OR,
			$condition,
		);
		
		return $this;
	}
	
	/**
	 * Helper method to build SQL for conditions
	 *
	 * @param array $conditions
	 * @param string $glue
	 * 
	 * @return string
	 */
	protected function _getConditionsSql(array $conditions,
		string $glue = PHP_EOL,
	): string
	{
		$sql = [];
		
		foreach($conditions as $condition)
		{
			if($condition instanceof Condition)
			{
				// nested condition
				if($condition->type === Condition::TYPE_NESTED
					&& count($condition->nested)
				)
				{
					$subConditionSql = $this->_getConditionsSql($condition->nested, ' ');
					
					$nestedSql = '(' . $subConditionSql . ')';
					
					$sql[] = $sql === []
						? $nestedSql
						: $condition->operator . ' ' . $nestedSql;
				}
				// single condition
				elseif($condition->type === Condition::TYPE_DEFAULT)
				{
					$sql[] = $condition->operator . ' ' . $condition->condition;
				}
			}
			else
			{
				// simple string condition
				$sql[] = $sql === []
					? $condition
					: Condition::OPERATOR_AND . ' ' . $condition;
			}
		}
		
		return implode($glue, $sql);
	}
}
