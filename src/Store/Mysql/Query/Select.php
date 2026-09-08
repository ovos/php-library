<?php
declare(strict_types=1);

namespace Ovos\Store\Mysql\Query;

use Ovos\Store\Mysql\Query;
use Ovos\Store\Mysql\Query\Traits\Ordering;

use function array_push;
use function implode;

/**
 * Select
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Select extends Query
{
	use Ordering;
	
	protected array $groupBy = [];
	
	protected array $having = [];
	
	protected array $havingValues = [];
	
	protected ?int $offset = null;
	
	/**
	 * @return string
	 */
	public function getSql(): string
	{
		$sql = 'SELECT ' . implode(', ', $this->columns) . PHP_EOL;
		$sql.= 'FROM ' . $this->getFrom() . PHP_EOL;
		$sql.= $this->getJoinsSql();
		
		if($this->conditions !== [])
		{
			$sql.= 'WHERE '
				. $this->getConditionsSql($this->conditions)
				. PHP_EOL;
		}
		
		if($this->groupBy !== [])
		{
			$sql.= 'GROUP BY '
				. implode(', ', $this->groupBy)
				. PHP_EOL;
		}
		
		if($this->having !== [])
		{
			$sql.= 'HAVING '
				. implode(PHP_EOL . 'AND ', $this->having)
				. PHP_EOL;
		}
		
		$sql.= $this->getOrderingSql();
		
		if($this->offset !== null)
		{
			$sql.= 'OFFSET ' . $this->offset
				. PHP_EOL;
		}
		
		return $sql;
	}
	
	public function getValues(): array
	{
		return [
			...$this->columnValues,
			...$this->joinValues,
			...$this->conditionValues,
			...$this->havingValues,
		];
	}
	
	/**
	 * A column as a string, or as a tuple [sql, ...values] when the
	 * expression carries `?` placeholders
	 */
	public function select(
		string|array ...$fields,
	): static
	{
		foreach($fields as $field)
		{
			[$sql, $values] = self::fragment($field);
			$this->columns[] = $sql;
			array_push($this->columnValues, ...$values);
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
	
	public function offset(
		?int $offset,
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
	
	/**
	 * HAVING conditions, ANDed: a string or a tuple [sql, ...values]
	 */
	public function having(
		string|array ...$conditions,
	): static
	{
		foreach($conditions as $condition)
		{
			[$sql, $values] = self::fragment($condition);
			$this->having[] = $sql;
			array_push($this->havingValues, ...$values);
		}
		
		return $this;
	}
}
