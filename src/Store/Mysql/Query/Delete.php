<?php
declare(strict_types=1);

namespace Ovos\Store\Mysql\Query;

use Ovos\Store\Mysql\Query;

/**
 * Delete
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
class Delete extends Query
{
	/**
	 * @return string
	 */
	public function getSql(): string
	{
		$sql = 'DELETE FROM ' . $this->_table . PHP_EOL;
		
		if($this->_conditions !== [])
		{
			$sql.= 'WHERE ' . implode(PHP_EOL . 'AND ', $this->_conditions) . PHP_EOL;
		}
		
		return $sql;
	}
}
