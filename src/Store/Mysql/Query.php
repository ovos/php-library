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
 * @author Marcin Gil <mg@ovos.at>
 */
abstract class Query
{
	// Conditions
	public const string CONDITION_TYPE_DEFAULT = 'default';
	public const string CONDITION_TYPE_NESTED = 'nested';
	public const string CONDITION_OPERATOR_AND = 'AND';
	public const string CONDITION_OPERATOR_OR = 'OR';
	
	protected ?string $alias = null;
	
	protected string $table;
	
	protected array $columns = [];
	
	protected array $leftJoins = [];
	
	protected array $innerJoins = [];
	
	protected array $conditions = [];
	
	protected string $conditionOperator = Condition::OPERATOR_AND;
	
	public function __construct(
		string $table,
	)
	{
		$this->setTable($table);
	}
	
	protected function getFrom(): string
	{
		return $this->alias !== null
			? $this->table . ' ' . $this->alias
			: $this->table;
	}
	
	public function setTable(
		string $table,
	): static
	{
		$this->table = $table;
		
		return $this;
	}
	
	public function getTable(): string
	{
		return $this->table;
	}
	
	abstract public function getSql(): string;
	
	public function __toString(): string
	{
		return $this->getSql();
	}
	
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
			&& $nestedCondition = $this->getNestedCondition($conditions[0]))
		{
			$this->conditions[] = $nestedCondition;
			
			return $this;
		}
		
		foreach($conditions as $condition)
		{
			$this->conditions[] = $condition;
		}
		
		return $this;
	}
	
	protected function getNestedCondition(
		callable $condition,
		string $operator = Condition::OPERATOR_AND,
	): ?Condition
	{
		$nestedQuery = clone $this;
		$nestedQuery->conditions = [];
		$nestedQuery->conditionOperator = Condition::OPERATOR_AND;
		
		$condition($nestedQuery);
		
		if(count($nestedQuery->conditions) === 0)
		{
			return null;
		}
		
		return new Condition
		(
			Condition::TYPE_NESTED,
			$operator,
			nested: $nestedQuery->conditions
		);
	}
	
	public function andWhere(
		string|callable ...$conditions,
	): static
	{
		return $this->where(...$conditions);
	}
	
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
			&& $nestedCondition= $this->getNestedCondition($conditions[0],
			Condition::OPERATOR_OR))
		{
			$this->conditions[] = $nestedCondition;
			
			return $this;
		}
		
		foreach($conditions as $condition)
		{
			$this->conditions[] = new Condition
			(
				Condition::TYPE_DEFAULT,
				Condition::OPERATOR_OR,
				$condition,
			);
		}
		
		return $this;
	}
	
	public function whereIn(
		string $field,
		array $values,
	): static
	{
		if(count($values) === 0)
		{
			return $this;
		}
		
		$condition = $field
			. ' IN ('
			. implode(', ', $values)
			. ')';
		$this->conditions[] = $condition;
		
		return $this;
	}
	
	public function andWhereIn(
		string $field,
		array $values,
	): static
	{
		return $this->whereIn($field, $values);
	}
	
	public function whereNotIn(
		string $field,
		array $values,
	): static
	{
		if(count($values) === 0)
		{
			return $this;
		}
		
		$condition = $field
			. ' NOT IN ('
			. implode(', ', $values)
			. ')';
		
		$this->conditions[] = $condition;
		
		return $this;
	}
	
	public function andWhereNotIn(
		string $field,
		array $values,
	): static
	{
		return $this->whereNotIn($field, $values);
	}
	
	public function orWhereNotIn(
		string $field,
		array $values,
	): static
	{
		if(count($values) === 0)
		{
			return $this;
		}
		
		$condition = $field
			. ' NOT IN ('
			. implode(', ', $values)
			. ')';
		$this->conditions[] = new Condition
		(
			Condition::TYPE_DEFAULT,
			Condition::OPERATOR_OR,
			$condition,
		);
		
		return $this;
	}
	
	/**
	 * Helper method to build SQL for conditions
	 */
	protected function getConditionsSql(
		array $conditions,
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
					$subConditionSql = $this->getConditionsSql($condition->nested, ' ');
					
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
