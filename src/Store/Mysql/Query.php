<?php
declare(strict_types=1);

namespace Ovos\Store\Mysql;

use Ovos\Pdo\Expression;
use Ovos\Store\Mysql\Query\Condition;

use function array_fill;
use function array_push;
use function array_shift;
use function array_values;
use function count;
use function implode;
use function is_array;
use function is_callable;

/**
 * Query
 *
 * A query carries its values. A condition, a join or a HAVING clause given as
 * a tuple [sql, ...values] records them next to the SQL; INSERT and UPDATE
 * columns are PHP values (an Expression is SQL and is written as given); and
 * getValues() hands everything back in the order the SQL emits it.
 * Store\Mysql::statement() prepares, binds by position and executes.
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
	
	// Joins
	public const string JOIN_LEFT = 'LEFT JOIN';
	public const string JOIN_INNER = 'INNER JOIN';
	
	protected ?string $alias = null;
	
	protected string $table;
	
	/**
	 * Column fragments: the SELECT list, UPDATE sets
	 */
	protected array $columns = [];
	
	/**
	 * The values of the column fragments: a SELECT tuple's, an UPDATE SET's
	 */
	protected array $columnValues = [];
	
	/**
	 * list<array{kind: string, sql: string}>, in call order
	 */
	protected array $joins = [];
	
	protected array $joinValues = [];
	
	protected array $conditions = [];
	
	protected array $conditionValues = [];
	
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
	
	/**
	 * The alias of the primary table: every query kind that emits FROM
	 * (SELECT, UPDATE, DELETE) may join and then needs one
	 */
	public function alias(
		string $alias,
	): static
	{
		$this->alias = $alias;
		
		return $this;
	}
	
	/**
	 * A join as a string, or as a tuple [sql, ...values] when its ON clause
	 * carries `?` placeholders; joins are emitted in the order written
	 */
	public function innerJoin(
		string|array ...$joins,
	): static
	{
		return $this->join(self::JOIN_INNER, $joins);
	}
	
	public function leftJoin(
		string|array ...$joins,
	): static
	{
		return $this->join(self::JOIN_LEFT, $joins);
	}
	
	protected function join(
		string $kind,
		array $joins,
	): static
	{
		foreach($joins as $join)
		{
			[$sql, $values] = self::fragment($join);
			$this->joins[] = ['kind' => $kind, 'sql' => $sql];
			array_push($this->joinValues, ...$values);
		}
		
		return $this;
	}
	
	protected function getJoinsSql(): string
	{
		$sql = '';
		foreach($this->joins as $join)
		{
			$sql.= $join['kind'] . ' ' . $join['sql'] . PHP_EOL;
		}
		
		return $sql;
	}
	
	/**
	 * A fragment given as a string, or as a tuple [sql, ...values]
	 *
	 * @return array{string, list<mixed>}
	 */
	protected static function fragment(
		mixed $fragment,
	): array
	{
		if(is_array($fragment) === false)
		{
			return [(string)$fragment, []];
		}
		
		$sql = (string)array_shift($fragment);
		
		return [$sql, array_values($fragment)];
	}
	
	/**
	 * What a column value becomes in the SQL: an Expression its text, any
	 * other value a `?` bound later
	 */
	protected static function placeholder(
		mixed $value,
	): string
	{
		return $value instanceof Expression ? (string)$value : '?';
	}
	
	abstract public function getSql(): string;
	
	/**
	 * The values bound to the `?` placeholders, in the order the SQL emits them
	 */
	abstract public function getValues(): array;
	
	public function __toString(): string
	{
		return $this->getSql();
	}
	
	/**
	 * Conditions, ANDed: a string, a tuple [sql, ...values], or a Closure
	 * building a nested group. `where(fn($q) => $q->where('a = 1')->orWhere('b = 1'))`
	 * emits `(a = 1 OR b = 1)`.
	 */
	public function where(
		string|array|callable ...$conditions,
	): static
	{
		return $this->addConditions($conditions, Condition::OPERATOR_AND);
	}
	
	public function andWhere(
		string|array|callable ...$conditions,
	): static
	{
		return $this->where(...$conditions);
	}
	
	public function orWhere(
		string|array|callable ...$conditions,
	): static
	{
		return $this->addConditions($conditions, Condition::OPERATOR_OR);
	}
	
	protected function addConditions(
		array $conditions,
		string $operator,
	): static
	{
		foreach($conditions as $condition)
		{
			// a tuple is never a callable here: arrays are [sql, ...values]
			if(is_array($condition) === false && is_callable($condition))
			{
				$nested = $this->getNestedCondition($condition, $operator);
				if($nested !== null)
				{
					$this->conditions[] = $nested;
				}
				
				continue;
			}
			
			[$sql, $values] = self::fragment($condition);
			$this->conditions[] = $operator === Condition::OPERATOR_OR
				? new Condition(Condition::TYPE_DEFAULT, Condition::OPERATOR_OR, $sql)
				: $sql;
			array_push($this->conditionValues, ...$values);
		}
		
		return $this;
	}
	
	/**
	 * The group a Closure builds on a clone of this query; its values join
	 * this query's where the group sits
	 */
	protected function getNestedCondition(
		callable $condition,
		string $operator = Condition::OPERATOR_AND,
	): ?Condition
	{
		$nestedQuery = clone $this;
		$nestedQuery->conditions = [];
		$nestedQuery->conditionValues = [];
		
		$condition($nestedQuery);
		
		if(count($nestedQuery->conditions) === 0)
		{
			return null;
		}
		
		array_push($this->conditionValues, ...$nestedQuery->conditionValues);
		
		return new Condition
		(
			Condition::TYPE_NESTED,
			$operator,
			nested: $nestedQuery->conditions
		);
	}
	
	/**
	 * `field IN (...)`. The values are written into the SQL as given (ints,
	 * or fragments) unless $bind: then one `?` per value, the values bound.
	 * An empty list adds no condition.
	 */
	public function whereIn(
		string $field,
		array $values,
		bool $bind = false,
	): static
	{
		return $this->addIn($field, $values, 'IN', Condition::OPERATOR_AND, $bind);
	}
	
	public function andWhereIn(
		string $field,
		array $values,
		bool $bind = false,
	): static
	{
		return $this->whereIn($field, $values, $bind);
	}
	
	public function orWhereIn(
		string $field,
		array $values,
		bool $bind = false,
	): static
	{
		return $this->addIn($field, $values, 'IN', Condition::OPERATOR_OR, $bind);
	}
	
	public function whereNotIn(
		string $field,
		array $values,
		bool $bind = false,
	): static
	{
		return $this->addIn($field, $values, 'NOT IN', Condition::OPERATOR_AND, $bind);
	}
	
	public function andWhereNotIn(
		string $field,
		array $values,
		bool $bind = false,
	): static
	{
		return $this->whereNotIn($field, $values, $bind);
	}
	
	public function orWhereNotIn(
		string $field,
		array $values,
		bool $bind = false,
	): static
	{
		return $this->addIn($field, $values, 'NOT IN', Condition::OPERATOR_OR, $bind);
	}
	
	protected function addIn(
		string $field,
		array $values,
		string $operator,
		string $glue,
		bool $bind,
	): static
	{
		$values = array_values($values);
		if($values === [])
		{
			return $this;
		}
		
		$list = $bind ? array_fill(0, count($values), '?') : $values;
		$sql = $field . ' ' . $operator . ' (' . implode(', ', $list) . ')';
		$this->conditions[] = $glue === Condition::OPERATOR_OR
			? new Condition(Condition::TYPE_DEFAULT, Condition::OPERATOR_OR, $sql)
			: $sql;
		if($bind)
		{
			array_push($this->conditionValues, ...$values);
		}
		
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
					$sql[] = $sql === []
						? $condition->condition
						: $condition->operator . ' ' . $condition->condition;
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
