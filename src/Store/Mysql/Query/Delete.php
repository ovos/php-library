<?php
declare(strict_types=1);

namespace Ovos\Store\Mysql\Query;

use Ovos\Store\Mysql\Query;
use Ovos\Store\Mysql\Query\Traits\Ordering;

use function implode;

/**
 * Delete
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Delete extends Query
{
	use Ordering;
	
	// used for multi-table deletes
	protected array $aliases = [];
	
	/**
	 * The tables rows are deleted FROM in a joined delete —
	 * `DELETE i FROM members i INNER JOIN …` names `i` here
	 */
	public function aliases(
		array $aliases,
	): static
	{
		$this->aliases = $aliases;
		
		return $this;
	}
	
	public function getSql(): string
	{
		$sql = 'DELETE';
		if($this->aliases !== [])
		{
			$sql.= ' ' . implode(', ', $this->aliases);
		}
		$sql.= PHP_EOL;
		$sql.= 'FROM ' . $this->getFrom() . PHP_EOL;
		$sql.= $this->getJoinsSql();
		
		if($this->conditions !== [])
		{
			$sql.= 'WHERE ' . $this->getConditionsSql($this->conditions)
				. PHP_EOL;
		}
		
		$sql.= $this->getOrderingSql();
		
		return $sql;
	}
	
	public function getValues(): array
	{
		return [
			...$this->joinValues,
			...$this->conditionValues,
		];
	}
}
