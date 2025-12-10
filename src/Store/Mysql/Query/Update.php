<?php
declare(strict_types=1);

namespace Ovos\Store\Mysql\Query;

use Ovos\Store\Mysql\Query;

use function implode;

/**
 * Update
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
class Update extends Query
{
	public function getSql(): string
	{
		$sql = 'UPDATE ' . $this->table . PHP_EOL;
		
		if($this->columns !== [])
		{
			$sql.= 'SET '
				. implode( ', ', $this->columns)
				. PHP_EOL;
		}
		
		if($this->conditions !== [])
		{
			$sql.= 'WHERE '
				. $this->getConditionsSql($this->conditions)
				. PHP_EOL;
		}
		
		return $sql;
	}
	
	/**
	 * Example usage:
	 * ->set(name: ':name', created_at: 'NOW()')
	 */
	public function set(mixed ...$columns): static
	{
		foreach($columns as $column => $value)
		{
			$this->columns[] = $column . ' = ' . $value;
		}
		
		return $this;
	}
}
