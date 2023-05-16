<?php
declare(strict_types=1);

namespace Ovos\Store\Mysql\Query;

use Ovos\Store\Mysql\Query;

/**
 * Insert
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
class Insert extends Query
{
	/**
	 * Meant to be used with prepared statements, that is why string values are not enclosed in quotes
	 * 
	 * @return string
	 */
	public function getSql(): string
	{
		$sql = 'INSERT INTO ' . $this->_table . PHP_EOL;
		$sql.= '(' . implode( ', ', array_keys($this->_columns)) . ')' . PHP_EOL;
		$sql.= 'VALUES (' . implode( ', ', array_values($this->_columns)) . ')' . PHP_EOL;
		
		return $sql;
	}
	
	/**
	 * Example usage:
	 * ->columns(name: ':name', created_at: 'NOW()')
	 * 
	 * @param string ...$columns
	 *
	 * @return self
	 */
    public function columns(mixed ...$columns): self
	{
		$this->_columns = $columns;
		
		return $this;
    }
}
