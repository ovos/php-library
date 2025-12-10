<?php
declare(strict_types=1);

namespace Ovos\Store\Mysql;

use Ovos\Store\Mysql\Query\Delete;
use Ovos\Store\Mysql\Query\Insert;
use Ovos\Store\Mysql\Query\Select;
use Ovos\Store\Mysql\Query\Update;

/**
 * QueryBuilder
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
class QueryBuilder
{
	protected string $table;
	
	public function __construct(
		string $table,
	)
	{
		$this->setTable($table);
	}
	
	public function setTable(
		string $table,
	): static
	{
		$this->table = $table;
		
		return $this;
	}
	
	public function getTable(): string
	{
		return $this->table;
	}
	
	public function delete(
		null|string|callable ...$conditions,
	): Delete
	{
		$query = new Delete($this->table);
		$query->where(...$conditions);
		
		return $query;
	}
	
	/**
	 * Example usage:
	 * ->columns(name: ':name', created_at: 'NOW()')
	 */
	public function insert(
		mixed ...$columns,
	): Insert
	{
		$query = new Insert($this->table);
		$query->columns(...$columns);
		
		return $query;
	}
	
	public function select(
		string ...$fields,
	): Select
	{
		$query = new Select($this->table);
		if(empty($fields))
		{
			$fields = ['*'];
		}
		$query->select(...$fields);
		
		return $query;
	}
	
	/**
	 * Example usage:
	 * ->set(name: ':name', created_at: 'NOW()')
	 */
	public function update(
		mixed ...$columns,
	): Update
	{
		$query = new Update($this->table);
		$query->set(...$columns);
		
		return $query;
	}
}
