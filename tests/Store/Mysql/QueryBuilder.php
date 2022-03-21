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
	/**
	 * @var BaseQueryBuilder
	 */
	protected BaseQueryBuilder $_queryBuilder;

	public function __construct()
	{
		$this->_queryBuilder = new BaseQueryBuilder('tests'); 
	}

	public function select()
	{
		$query = $this->_queryBuilder->select('t.id, t.name, t.created_at')
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
	
	public function update()
	{
		$query = $this->_queryBuilder->update(name: 'name', modified_at: 'NOW()')
			->where('finished_at IS NULL');
			
		return $query->getSql() === 'UPDATE tests'
			. PHP_EOL . 'SET name = name, modified_at = NOW()'
			. PHP_EOL . 'WHERE finished_at IS NULL'
			. PHP_EOL;
	}
	
	public function insert()
	{
		$query = $this->_queryBuilder->insert(name: ':name', created_at: 'NOW()');
		
		return $query->getSql() === 'INSERT INTO tests'
			. PHP_EOL . '(name, created_at)'
			. PHP_EOL . 'VALUES (:name, NOW())'
			. PHP_EOL;
	}
	
	public function delete()
	{
		$query = $this->_queryBuilder->delete('id = :id');
		
		return $query->getSql() === 'DELETE FROM tests'
			. PHP_EOL . 'WHERE id = :id'
			. PHP_EOL;
	}
}
