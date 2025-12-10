<?php
declare(strict_types=1);

namespace Tests\Store\Mysql;

use Ovos\Test;
use Ovos\Store\Mysql\QueryBuilder as BaseQueryBuilder;

/**
 * QueryBuilder
 *
 * @package Tests
 * @author Marcin Gil <mg@ovos.at>
 */
class QueryBuilder extends Test
{
	protected BaseQueryBuilder $queryBuilder;
	
	public function __construct()
	{
		$this->queryBuilder = new BaseQueryBuilder('tests'); 
	}
	
	public function select(): bool
	{
		$query = $this->queryBuilder->select('t.id, t.name, t.created_at')
			->alias('t')
			->leftJoin('tests_groups tr ON tr.id_test = t.id')
			->where('t.finished_at IS NOT NULL')
			->andWhere('tr.active = true')
			->andWhereIn('t.status', [1, 2])
			->andWhereIn('t.status', []) // this should be ignored
			->andWhereNotIn('t.status', [3])
			->orderBy('t.started_at DESC')
			->limit(10)
			->offset(20);
		
		return $query->getSql() === 'SELECT t.id, t.name, t.created_at'
			. PHP_EOL . 'FROM tests t'
			. PHP_EOL . 'LEFT JOIN tests_groups tr ON tr.id_test = t.id'
			. PHP_EOL . 'WHERE t.finished_at IS NOT NULL'
			. PHP_EOL . 'AND tr.active = true'
			. PHP_EOL . 'AND t.status IN (1, 2)'
			. PHP_EOL . 'AND t.status NOT IN (3)'
			. PHP_EOL . 'ORDER BY t.started_at DESC'
			. PHP_EOL . 'LIMIT 10'
			. PHP_EOL . 'OFFSET 20'
			. PHP_EOL;
	}
	
	public function update(): bool
	{
		$query = $this->queryBuilder->update(name: 'name', modified_at: 'NOW()')
			->where('finished_at IS NULL');
		
		return $query->getSql() === 'UPDATE tests'
			. PHP_EOL . 'SET name = name, modified_at = NOW()'
			. PHP_EOL . 'WHERE finished_at IS NULL'
			. PHP_EOL;
	}
	
	public function insert(): bool
	{
		$query = $this->queryBuilder->insert(name: ':name', created_at: 'NOW()');
		
		return $query->getSql() === 'INSERT INTO tests'
			. PHP_EOL . '(name, created_at)'
			. PHP_EOL . 'VALUES (:name, NOW())'
			. PHP_EOL;
	}
	
	public function delete(): bool
	{
		$query = $this->queryBuilder->delete('active = 0',
			'role = :role'
		);
		
		return $query->getSql() === 'DELETE'
			. PHP_EOL . 'FROM tests'
			. PHP_EOL . 'WHERE active = 0'
			. PHP_EOL . 'AND role = :role'
			. PHP_EOL;
	}
	
	public function deleteWhere(): bool
	{
		$query = $this->queryBuilder
			->delete()
			->where('active = 0',
				'role = :role'
			);
		
		return $query->getSql() === 'DELETE'
			. PHP_EOL . 'FROM tests'
			. PHP_EOL . 'WHERE active = 0'
			. PHP_EOL . 'AND role = :role'
			. PHP_EOL;
	}
	
	public function whereNested(): bool
	{
		$query = $this->queryBuilder->select('id, name, role, active, created_at')
			->where('created_at > NOW() - INTERVAL 1 MONTH')
			->where(function($query)
			{
				$query
					->where('active = 1')
					->orWhere(function($query)
					{
						$query
							->where('active = 0')
							->where('role = "admin"');
					});
			});
		
		return $query->getSql() === 'SELECT id, name, role, active, created_at'
			. PHP_EOL . 'FROM tests'
			. PHP_EOL . 'WHERE created_at > NOW() - INTERVAL 1 MONTH'
			. PHP_EOL . 'AND (active = 1 OR (active = 0 AND role = "admin"))'
			. PHP_EOL;
	}
	
	public function whereAdditional(): bool
	{
		// using multiple conditions with where()
		$query1 = $this->queryBuilder->select('id, name, email')
			->where('active = 1', 'created_at > NOW() - INTERVAL 1 MONTH', 'role = "user"');
		
		$expected1 = 'SELECT id, name, email'
			. PHP_EOL . 'FROM tests'
			. PHP_EOL . 'WHERE active = 1'
			. PHP_EOL . 'AND created_at > NOW() - INTERVAL 1 MONTH'
			. PHP_EOL . 'AND role = "user"'
			. PHP_EOL;
		
		// using multiple conditions with orWhere()
		$query2 = $this->queryBuilder->select('id, name, email')
			->where('active = 1')
			->orWhere('role = "admin"', 'role = "manager"', 'role = "supervisor"');
		
		$expected2 = 'SELECT id, name, email'
			. PHP_EOL . 'FROM tests'
			. PHP_EOL . 'WHERE active = 1'
			. PHP_EOL . 'OR role = "admin"'
			. PHP_EOL . 'OR role = "manager"'
			. PHP_EOL . 'OR role = "supervisor"'
			. PHP_EOL;
		
		// combining where() with additionalConditions and nested conditions
		$query3 = $this->queryBuilder->select('id, name, email')
			->where('active = 1', 'verified = 1')
			->where(function($query)
			{
				$query
					->where('role = "user"')
					->orWhere('has_access = 1', 'is_special = 1');
			});
		
		$expected3 = 'SELECT id, name, email'
			. PHP_EOL . 'FROM tests'
			. PHP_EOL . 'WHERE active = 1'
			. PHP_EOL . 'AND verified = 1'
			. PHP_EOL . 'AND (role = "user" OR has_access = 1 OR is_special = 1)'
			. PHP_EOL;
		
		return $query1->getSql() === $expected1
			&& $query2->getSql() === $expected2
			&& $query3->getSql() === $expected3
		;
	}
}
