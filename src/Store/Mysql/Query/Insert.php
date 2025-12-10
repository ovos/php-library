<?php
declare(strict_types=1);

namespace Ovos\Store\Mysql\Query;

use Ovos\Store\Mysql\Query;

use function implode;
use function array_keys;
use function array_values;

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
	 */
	public function getSql(): string
	{
		$sql = 'INSERT INTO ' . $this->table . PHP_EOL;
		$sql.= '(' . implode( ', ', array_keys($this->columns)) . ')'
			. PHP_EOL;
		$sql.= 'VALUES (' . implode( ', ', array_values($this->columns)) . ')'
			. PHP_EOL;
		
		return $sql;
	}
	
	/**
	 * Example usage:
	 * ->columns(name: ':name', created_at: 'NOW()')
	 */
	public function columns(
		mixed ...$columns,
	): static
	{
		$this->columns = $columns;
		
		return $this;
	}
}
