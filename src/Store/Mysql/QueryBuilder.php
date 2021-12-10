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
	/**
	 * @var string
	 */
	protected string $_table;
	
	public function __construct(string $table)
	{
		$this->setTable($table);
	}
	
	public function setTable(string $table): self
	{
		$this->_table = $table;
		
		return $this;
	}
	
	public function getTable(): string
	{
		return $this->_table;
	}

	/**
	 * @param string ...$conditions
	 *
	 * @return Delete
	 */
	public function delete(string ...$conditions): Delete
	{
		$query = new Delete($this->_table);
		$query->where(...$conditions);
		
		return $query;
	}
	
	/**
	 * Example usage:
	 * ->columns(name: ':name', created_at: 'NOW()')
	 * 
	 * @param string ...$columns
	 *
	 * @return Update
	 */
	public function insert(string ...$columns): Insert
	{
		$query = new Insert($this->_table);
		$query->columns(...$columns);
		
		return $query;
	}

	/**
	 * @param ...$fields
	 *
	 * @return Select
	 */
	public function select(string ...$fields): Select
	{
		$query = new Select($this->_table);
		$query->select(...$fields);
		
		return $query;
	}

	/**
	 * Example usage:
	 * ->set(name: ':name', created_at: 'NOW()')
	 * 
	 * @param string ...$columns
	 *
	 * @return Update
	 */
	public function update(string ...$columns): Update
	{
		$query = new Update($this->_table);
		$query->set(...$columns);
		
		return $query;
	}
}
