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
	// used for multi-table deletes
	protected array $aliases = [];
	
	public function aliases(
		array $aliases,
	): static
	{
		$this->aliases = $aliases;
		
		return $this;
	}
	
	public function getSql(): string
	{
		$sql = 'DELETE' . PHP_EOL;
		if($this->aliases !== [])
		{
			$sql.= ' ' . implode(', ', $this->aliases);
		}
		$sql.= 'FROM ' . $this->getFrom() . PHP_EOL;
		
		if($this->leftJoins !== [])
		{
			$sql.= 'LEFT JOIN '
				. implode(PHP_EOL . 'LEFT JOIN ', $this->leftJoins)
				. PHP_EOL;
		}
		
		if($this->innerJoins !== [])
		{
			$sql.= 'INNER JOIN '
			. implode(PHP_EOL . 'INNER JOIN ', $this->innerJoins)
			. PHP_EOL;
		}
		
		if($this->conditions !== [])
		{
			$sql.= 'WHERE ' . $this->getConditionsSql($this->conditions)
				. PHP_EOL;
		}
		
		return $sql;
	}
}
