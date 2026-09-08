<?php
declare(strict_types=1);

namespace Ovos\Store\Mysql\Query\Traits;

use function implode;

/**
 * ORDER BY and LIMIT — SELECT has them, and so do single-table UPDATE and
 * DELETE (a batched purge deletes the oldest N). LIMIT is an int in the SQL
 * text: native prepares refuse a string-bound placeholder there.
 *
 * @author Marcin Gil <mg@ovos.at>
 */
trait Ordering
{
	protected array $orderBy = [];
	
	protected ?int $limit = null;
	
	public function orderBy(
		string ...$arguments,
	): static
	{
		foreach($arguments as $argument)
		{
			$this->orderBy[] = $argument;
		}
		
		return $this;
	}
	
	public function limit(
		?int $limit,
	): static
	{
		$this->limit = $limit;
		
		return $this;
	}
	
	protected function getOrderingSql(): string
	{
		$sql = '';
		
		if($this->orderBy !== [])
		{
			$sql.= 'ORDER BY '
				. implode(', ', $this->orderBy)
				. PHP_EOL;
		}
		
		if($this->limit !== null)
		{
			$sql.= 'LIMIT ' . $this->limit
				. PHP_EOL;
		}
		
		return $sql;
	}
}
