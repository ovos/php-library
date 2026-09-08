<?php
declare(strict_types=1);

namespace Tests\Store\Mysql;

use Ovos\Pdo\Expression;
use Ovos\Test;
use Ovos\Store\Mysql\QueryBuilder as BaseQueryBuilder;

/**
 * QueryBuilder
 *
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
			. PHP_EOL
			&& $query->getValues() === [];
	}
	
	/**
	 * RULE: UPDATE columns are PHP values, bound; an Expression is SQL,
	 * written as given; a null binds NULL
	 */
	public function update(): bool
	{
		$query = $this->queryBuilder->update(name: 'Test', modified_at: new Expression('NOW()'), note: null)
			->where('finished_at IS NULL');
		
		return $query->getSql() === 'UPDATE tests'
			. PHP_EOL . 'SET name = ?, modified_at = NOW(), note = ?'
			. PHP_EOL . 'WHERE finished_at IS NULL'
			. PHP_EOL
			&& $query->getValues() === ['Test', null];
	}
	
	/**
	 * RULE: INSERT columns are PHP values, bound; an Expression is SQL
	 */
	public function insert(): bool
	{
		$query = $this->queryBuilder->insert(name: 'Test', created_at: new Expression('NOW()'));
		
		return $query->getSql() === 'INSERT INTO tests'
			. PHP_EOL . '(name, created_at)'
			. PHP_EOL . 'VALUES (?, NOW())'
			. PHP_EOL
			&& $query->getValues() === ['Test'];
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
	
	/**
	 * A joined delete: the alias names the primary table, aliases() the
	 * table rows are deleted from, and the join setters are the Query
	 * base's: a Delete may join like a Select
	 */
	public function deleteJoin(): bool
	{
		$query = $this->queryBuilder->delete()
			->alias('t')
			->aliases(['t'])
			->innerJoin('tests_groups tr ON tr.id_test = t.id')
			->where('tr.closed_at < ?')
			->whereIn('t.status', ['?', '?']);
		
		$expected = 'DELETE t'
			. PHP_EOL . 'FROM tests t'
			. PHP_EOL . 'INNER JOIN tests_groups tr ON tr.id_test = t.id'
			. PHP_EOL . 'WHERE tr.closed_at < ?'
			. PHP_EOL . 'AND t.status IN (?, ?)'
			. PHP_EOL;
		
		$orphans = $this->queryBuilder->delete()
			->alias('t')
			->aliases(['t'])
			->leftJoin('tests_groups tr ON tr.id_test = t.id')
			->where('tr.id IS NULL');
		
		$expectedOrphans = 'DELETE t'
			. PHP_EOL . 'FROM tests t'
			. PHP_EOL . 'LEFT JOIN tests_groups tr ON tr.id_test = t.id'
			. PHP_EOL . 'WHERE tr.id IS NULL'
			. PHP_EOL;
		
		return $query->getSql() === $expected
			&& $orphans->getSql() === $expectedOrphans;
	}
	
	public function insertIgnore(): bool
	{
		$query = $this->queryBuilder->insert(name: 'Test', created_at: new Expression('NOW()'))
			->ignore();
		
		$plain = $this->queryBuilder->insert(name: 'Test')
			->ignore()
			->ignore(false);
		
		return $query->getSql() === 'INSERT IGNORE INTO tests'
			. PHP_EOL . '(name, created_at)'
			. PHP_EOL . 'VALUES (?, NOW())'
			. PHP_EOL
			&& $plain->getSql() === 'INSERT INTO tests'
			. PHP_EOL . '(name)'
			. PHP_EOL . 'VALUES (?)'
			. PHP_EOL;
	}
	
	/**
	 * Multi-argument GROUP BY / ORDER BY lists are comma-separated
	 */
	public function groupByOrderBy(): bool
	{
		$query = $this->queryBuilder->select('producer', 'verdict', 'COUNT(*) AS n')
			->where('created_at >= NOW() - INTERVAL ? DAY')
			->groupBy('producer', 'verdict')
			->orderBy('producer', 'verdict');
		
		return $query->getSql() === 'SELECT producer, verdict, COUNT(*) AS n'
			. PHP_EOL . 'FROM tests'
			. PHP_EOL . 'WHERE created_at >= NOW() - INTERVAL ? DAY'
			. PHP_EOL . 'GROUP BY producer, verdict'
			. PHP_EOL . 'ORDER BY producer, verdict'
			. PHP_EOL;
	}
	
	/**
	 * RULE: a clause given as a tuple [sql, ...values] records its values,
	 * and getValues() hands them back in the order the SQL emits them:
	 * columns, joins, conditions (a nested group's where it sits), HAVING,
	 * whatever order the clauses were written in.
	 */
	public function values(): bool
	{
		$query = $this->queryBuilder->select('t.id', ['IF(t.kind = ?, 1, 0) AS flagged', 'error'])
			->alias('t')
			->having(['COUNT(*) > ?', 2])
			->where(['t.created_at >= NOW() - INTERVAL ? DAY', 7])
			->innerJoin(['tests_groups tr ON tr.id_test = t.id AND tr.kind = ?', 'core'])
			->where(function($query)
			{
				$query
					->where(['t.active = ?', 1])
					->orWhere(['t.role = ?', 'admin']);
			})
			->whereIn('t.status', ['open', 'muted'], bind: true)
			->orWhereIn('t.kind', [3, 4])
			->whereNotIn('t.state', [], bind: true) // nothing to bind, no condition
			->groupBy('t.id');
		
		return $query->getSql() === 'SELECT t.id, IF(t.kind = ?, 1, 0) AS flagged'
			. PHP_EOL . 'FROM tests t'
			. PHP_EOL . 'INNER JOIN tests_groups tr ON tr.id_test = t.id AND tr.kind = ?'
			. PHP_EOL . 'WHERE t.created_at >= NOW() - INTERVAL ? DAY'
			. PHP_EOL . 'AND (t.active = ? OR t.role = ?)'
			. PHP_EOL . 'AND t.status IN (?, ?)'
			. PHP_EOL . 'OR t.kind IN (3, 4)'
			. PHP_EOL . 'GROUP BY t.id'
			. PHP_EOL . 'HAVING COUNT(*) > ?'
			. PHP_EOL
			&& $query->getValues() === ['error', 'core', 7, 1, 'admin', 'open', 'muted', 2];
	}
	
	/**
	 * RULE: every argument of where() counts (a Closure is a group, a
	 * string a condition, a tuple a condition with values) and a group that
	 * opens with orWhere() does not start with a dangling OR.
	 */
	public function whereClosureWithMore(): bool
	{
		$query = $this->queryBuilder->select('id')
			->where(function($query)
			{
				$query
					->orWhere('a = 1')
					->orWhere('b = 1');
			}, 'c = 1', ['d = ?', 4]);
		
		return $query->getSql() === 'SELECT id'
			. PHP_EOL . 'FROM tests'
			. PHP_EOL . 'WHERE (a = 1 OR b = 1)'
			. PHP_EOL . 'AND c = 1'
			. PHP_EOL . 'AND d = ?'
			. PHP_EOL
			&& $query->getValues() === [4];
	}
	
	/**
	 * RULE: joins are emitted in the order written; a LEFT JOIN and an
	 * INNER JOIN live side by side.
	 */
	public function joinsInCallOrder(): bool
	{
		$query = $this->queryBuilder->select('t.id')
			->alias('t')
			->leftJoin('a ON a.id = t.a_id')
			->innerJoin('b ON b.id = t.b_id')
			->leftJoin('c ON c.id = b.c_id');
		
		return $query->getSql() === 'SELECT t.id'
			. PHP_EOL . 'FROM tests t'
			. PHP_EOL . 'LEFT JOIN a ON a.id = t.a_id'
			. PHP_EOL . 'INNER JOIN b ON b.id = t.b_id'
			. PHP_EOL . 'LEFT JOIN c ON c.id = b.c_id'
			. PHP_EOL;
	}
	
	/**
	 * RULE: an UPDATE may alias, join, order and limit; the SET values come
	 * after the join's and before the WHERE's.
	 */
	public function updateJoinValues(): bool
	{
		$query = $this->queryBuilder->update(state: 'resolved', modified_at: new Expression('NOW()'))
			->alias('t')
			->innerJoin(['tests_groups tr ON tr.id_test = t.id AND tr.kind = ?', 'core'])
			->where(['t.id = ?', 5])
			->orderBy('t.id ASC')
			->limit(10);
		
		return $query->getSql() === 'UPDATE tests t'
			. PHP_EOL . 'INNER JOIN tests_groups tr ON tr.id_test = t.id AND tr.kind = ?'
			. PHP_EOL . 'SET state = ?, modified_at = NOW()'
			. PHP_EOL . 'WHERE t.id = ?'
			. PHP_EOL . 'ORDER BY t.id ASC'
			. PHP_EOL . 'LIMIT 10'
			. PHP_EOL
			&& $query->getValues() === ['core', 'resolved', 5];
	}
	
	/**
	 * RULE: a DELETE may order and limit (the batched purge), and takes
	 * tuple conditions straight from the builder
	 */
	public function deleteOrderedLimited(): bool
	{
		$query = $this->queryBuilder->delete(['created_at < NOW() - INTERVAL ? DAY', 30])
			->orderBy('id ASC')
			->limit(1000);
		
		return $query->getSql() === 'DELETE'
			. PHP_EOL . 'FROM tests'
			. PHP_EOL . 'WHERE created_at < NOW() - INTERVAL ? DAY'
			. PHP_EOL . 'ORDER BY id ASC'
			. PHP_EOL . 'LIMIT 1000'
			. PHP_EOL
			&& $query->getValues() === [30];
	}
	
	/**
	 * RULE: row() and rows() add rows in the first row's column order, one
	 * VALUES tuple each, values flattened in order and an Expression bound
	 * to nothing; ON DUPLICATE KEY UPDATE follows with its own values last.
	 */
	public function insertRowsDuplicate(): bool
	{
		$query = $this->queryBuilder->insert(a: 1, b: 'x', created_at: new Expression('NOW()'))
			->row(a: 2, b: 'y', created_at: new Expression('NOW()'))
			->rows(['a' => 3, 'b' => null, 'created_at' => new Expression('NOW()')])
			->onDuplicateKeyUpdate(b: new Expression('VALUES(b)'), modified_at: '2026-09-08');
		
		$fromRows = $this->queryBuilder->insert()
			->rows(['a' => 7, 'b' => false], ['b' => true, 'a' => 8]); // keyed, not positional
		
		return $query->getSql() === 'INSERT INTO tests'
			. PHP_EOL . '(a, b, created_at)'
			. PHP_EOL . 'VALUES (?, ?, NOW()), (?, ?, NOW()), (?, ?, NOW())'
			. PHP_EOL . 'ON DUPLICATE KEY UPDATE b = VALUES(b), modified_at = ?'
			. PHP_EOL
			&& $query->getValues() === [1, 'x', 2, 'y', 3, null, '2026-09-08']
			&& $fromRows->getSql() === 'INSERT INTO tests'
			. PHP_EOL . '(a, b)'
			. PHP_EOL . 'VALUES (?, ?), (?, ?)'
			. PHP_EOL
			&& $fromRows->getValues() === [7, false, 8, true];
	}
}
