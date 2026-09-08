<?php
declare(strict_types=1);

namespace Ovos\Store\Mysql\Query;

use Ovos\Pdo\Expression;
use Ovos\Store\Mysql\Query;
use Ovos\Store\Mysql\Query\Traits\Ordering;

use function implode;

/**
 * Update
 *
 * Columns are PHP values: `update(state: $state, modified_at: new Expression('NOW()'))`
 * emits `SET state = ?, modified_at = NOW()` and binds $state.
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Update extends Query
{
	use Ordering;
	
	public function getSql(): string
	{
		$sql = 'UPDATE ' . $this->getFrom() . PHP_EOL;
		$sql.= $this->getJoinsSql();
		
		if($this->columns !== [])
		{
			$sql.= 'SET '
				. implode(', ', $this->columns)
				. PHP_EOL;
		}
		
		if($this->conditions !== [])
		{
			$sql.= 'WHERE '
				. $this->getConditionsSql($this->conditions)
				. PHP_EOL;
		}
		
		$sql.= $this->getOrderingSql();
		
		return $sql;
	}
	
	/**
	 * Joins come before SET in the SQL, so their values come first
	 */
	public function getValues(): array
	{
		return [
			...$this->joinValues,
			...$this->columnValues,
			...$this->conditionValues,
		];
	}
	
	/**
	 * `set(name: $name, modified_at: new Expression('NOW()'))`: a value is
	 * bound, an Expression written as given
	 */
	public function set(
		mixed ...$columns,
	): static
	{
		foreach($columns as $column => $value)
		{
			$this->columns[] = $column . ' = ' . self::placeholder($value);
			if($value instanceof Expression === false)
			{
				$this->columnValues[] = $value;
			}
		}
		
		return $this;
	}
}
