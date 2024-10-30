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
			$sql.= 'WHERE ' . implode(PHP_EOL . 'AND ', $this->_conditions) . PHP_EOL;
		}
		
		return $sql;
	}
	
	/**
	 * Example usage:
	 * ->set(name: ':name', created_at: 'NOW()')
	 * 
	 * @param mixed ...$columns
	 *
	 * @return self
	 */
    public function set(mixed ...$columns): self
	{
		foreach($columns as $column => $value)
		{
			$this->_columns[] = $column . ' = ' . (string)$value;
		}
		
		return $this;
    }
}
