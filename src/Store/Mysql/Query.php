<?php
declare(strict_types=1);

namespace Ovos\Store\Mysql;

/**
 * Query
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
abstract class Query
{
	/**
	 * @var string
	 */
	protected string $_table;

	/**
	 * @var array
	 */
	protected array $_columns = [];
	
	/**
	 * @var array
	 */
	protected array $_conditions = [];

	/**
	 * @param string $table
	 */
	public function __construct(string $table)
	{
		$this->setTable($table);
	}

	/**
	 * @param string $table
	 *
	 * @return $this
	 */
	public function setTable(string $table): self
	{
		$this->_table = $table;
		
		return $this;
	}
	
	/**
	 * @return string
	 */
	public function getTable(): string
	{
		return $this->_table;
	}

	/**
	 * @return string
	 */
	abstract public function getSql(): string;
	
	public function __toString(): string
	{
		return $this->getSql();
	}
	
	/**
	 * @param string ...$conditions
	 *
	 * @return $this
	 */
	public function where(string ...$conditions): self
	{
		foreach($conditions as $condition)
		{
			$this->_conditions[] = $condition;
		}
		
		return $this;
	}
	
	/**
	 * @param string ...$conditions
	 *
	 * @return $this
	 */
	public function andWhere(string ...$conditions): self
	{
		return $this->where(...$conditions);
	}
}
