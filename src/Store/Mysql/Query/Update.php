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
	/**
	 * @return string
	 */
	public function getSql(): string
	{
		$sql = 'UPDATE ' . $this->_table . PHP_EOL;
		
		if($this->_columns !== [])
		{
			$sql.= 'SET ' . implode( ', ', $this->_columns) . PHP_EOL;
		}
		
		if($this->_conditions !== [])
		{
			$sql.= 'WHERE ' . $this->_getConditionsSql($this->_conditions) . PHP_EOL;
		}
		
		return $sql;
	}
	
	/**
	 * Example usage:
	 * ->set(name: ':name', created_at: 'NOW()')
	 *
	 * @param mixed ...$columns
	 *
	 * @return static
	 */
	public function set(mixed ...$columns): static
	{
		foreach($columns as $column => $value)
		{
			$this->_columns[] = $column . ' = ' . $value;
		}
		
		return $this;
	}
}
