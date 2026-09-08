<?php
declare(strict_types=1);

namespace Ovos\Store\Mysql\Query;

use Ovos\Pdo\Expression;
use Ovos\Store\Mysql\Query;

use function array_keys;
use function implode;
use function reset;

/**
 * Insert
 *
 * Columns are PHP values: `insert(name: $name, created_at: new Expression('NOW()'))`
 * emits `(name, created_at) VALUES (?, NOW())` and binds $name. An Expression
 * is SQL and is written as given; everything else travels as a bound value.
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Insert extends Query
{
	protected bool $ignore = false;
	
	/**
	 * list<array<string, mixed>>: one VALUES tuple per row, every row naming
	 * the first row's columns
	 */
	protected array $rows = [];
	
	/**
	 * ON DUPLICATE KEY UPDATE, column => value|Expression
	 */
	protected array $duplicate = [];
	
	public function getSql(): string
	{
		$columns = $this->getColumns();
		
		$sql = ($this->ignore ? 'INSERT IGNORE INTO ' : 'INSERT INTO ')
			. $this->table . PHP_EOL;
		$sql.= '(' . implode(', ', $columns) . ')' . PHP_EOL;
		
		$tuples = [];
		foreach($this->rows as $row)
		{
			$placeholders = [];
			foreach($columns as $column)
			{
				$placeholders[] = self::placeholder($row[$column] ?? null);
			}
			$tuples[] = '(' . implode(', ', $placeholders) . ')';
		}
		$sql.= 'VALUES ' . implode(', ', $tuples) . PHP_EOL;
		
		if($this->duplicate !== [])
		{
			$sets = [];
			foreach($this->duplicate as $column => $value)
			{
				$sets[] = $column . ' = ' . self::placeholder($value);
			}
			$sql.= 'ON DUPLICATE KEY UPDATE ' . implode(', ', $sets) . PHP_EOL;
		}
		
		return $sql;
	}
	
	/**
	 * The rows' values in column order, then ON DUPLICATE KEY UPDATE's;
	 * an Expression is SQL and binds nothing
	 */
	public function getValues(): array
	{
		$columns = $this->getColumns();
		$values = [];
		foreach($this->rows as $row)
		{
			foreach($columns as $column)
			{
				$value = $row[$column] ?? null;
				if($value instanceof Expression === false)
				{
					$values[] = $value;
				}
			}
		}
		foreach($this->duplicate as $value)
		{
			if($value instanceof Expression === false)
			{
				$values[] = $value;
			}
		}
		
		return $values;
	}
	
	/**
	 * @return list<string> the first row's columns, the shape of every row
	 */
	protected function getColumns(): array
	{
		return $this->rows !== [] ? array_keys(reset($this->rows)) : [];
	}
	
	/**
	 * The first row, `columns(name: $name, created_at: new Expression('NOW()'))`;
	 * replaces what rows() collected before
	 */
	public function columns(
		mixed ...$columns,
	): static
	{
		$this->rows = $columns === [] ? [] : [$columns];
		
		return $this;
	}
	
	/**
	 * One more row, named: `->row(name: 'a')->row(name: 'b')`
	 */
	public function row(
		mixed ...$columns,
	): static
	{
		$this->rows[] = $columns;
		
		return $this;
	}
	
	/**
	 * More rows in one statement, each `column => value|Expression`, all
	 * naming the first row's columns: `->rows(['a' => 1], ['a' => 2])`
	 * emits `VALUES (?), (?)`
	 */
	public function rows(
		array ...$rows,
	): static
	{
		foreach($rows as $row)
		{
			$this->rows[] = $row;
		}
		
		return $this;
	}
	
	/**
	 * INSERT IGNORE: a row the unique key already holds is left alone
	 */
	public function ignore(
		bool $ignore = true,
	): static
	{
		$this->ignore = $ignore;
		
		return $this;
	}
	
	/**
	 * `onDuplicateKeyUpdate(events: new Expression('events + VALUES(events)'), last_at: $at)`;
	 * these values come after the rows'
	 */
	public function onDuplicateKeyUpdate(
		mixed ...$columns,
	): static
	{
		foreach($columns as $column => $value)
		{
			$this->duplicate[$column] = $value;
		}
		
		return $this;
	}
}
