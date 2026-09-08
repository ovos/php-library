<?php
declare(strict_types=1);

namespace Tests\Store\Mysql;

use Ovos\Test;
use Ovos\Test\Internal;
use Ovos\Model\Mysql as Model;
use Ovos\Pdo\Expression;
use Ovos\Store\Mysql as Store;
use Ovos\Store\Mysql\Traits\Find;
use Override;
use PDO;

/**
 * ExecuteFind
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class ExecuteFind extends Test
{
	protected object $store;
	
	public function __construct()
	{
		$this->store = new TestStore;
		
		$this->store->source()->exec('
			CREATE TABLE IF NOT EXISTS tests_find (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				name VARCHAR(128) NULL,
				category VARCHAR(64) NULL,
				active TINYINT UNSIGNED NOT NULL DEFAULT 1,
				PRIMARY KEY (id),
				INDEX category (category ASC),
				INDEX active (active ASC)
			)
			ENGINE = InnoDB;
		');
		
		$this->store->source()->exec('
			INSERT INTO tests_find (id, name, category, active)
			VALUES
				(1, "Alpha", "fruit", 1),
				(2, "Beta", "vegetable", 1),
				(3, "Gamma", "fruit", 0),
				(4, "Delta", "grain", 1),
				(5, "Epsilon", "vegetable", 0);
		');
	}
	
	public function where(): bool
	{
		$statement = $this->store->executeFind(where: ['active' => 1]);
		$results = $statement->fetchAll(PDO::FETCH_CLASS, TestModel::class);
		
		return count($results) === 3
			&& $results[0]->name === 'Alpha';
	}
	
	public function whereMultiple(): bool
	{
		$statement = $this->store->executeFind(where: [
			'category' => 'fruit',
			'active' => 1,
		]);
		$results = $statement->fetchAll(PDO::FETCH_CLASS, TestModel::class);
		
		return count($results) === 1
			&& $results[0]->name === 'Alpha';
	}
	
	public function select(): bool
	{
		$statement = $this->store->executeFind(
			where: ['id' => 1],
			select: 'id, name',
		);
		$result = $statement->fetchObject(TestModel::class);
		
		return $result->id === 1
			&& $result->name === 'Alpha'
			&& $result->category === null;
	}
	
	public function orWhere(): bool
	{
		$statement = $this->store->executeFind(orWhere: [
			'category' => 'fruit',
			'category' => 'grain',
		]);
		$results = $statement->fetchAll(PDO::FETCH_CLASS, TestModel::class);
		
		// duplicate key in array means only 'grain' is kept
		return count($results) === 1
			&& $results[0]->name === 'Delta';
	}
	
	public function whereIn(): bool
	{
		$statement = $this->store->executeFind(whereIn: [
			'id' => [1, 2, 4],
		]);
		$results = $statement->fetchAll(PDO::FETCH_CLASS, TestModel::class);
		
		return count($results) === 3
			&& $results[0]->name === 'Alpha'
			&& $results[2]->name === 'Delta';
	}
	
	public function whereNotIn(): bool
	{
		$statement = $this->store->executeFind(whereNotIn: [
			'id' => [1, 2],
		]);
		$results = $statement->fetchAll(PDO::FETCH_CLASS, TestModel::class);
		
		return count($results) === 3
			&& $results[0]->name === 'Gamma';
	}
	
	public function isNull(): bool
	{
		// Insert a row with NULL category
		$this->store->source()->exec('
			INSERT INTO tests_find (id, name, category, active)
			VALUES (6, "Zeta", null, 1);
		');
		
		$statement = $this->store->executeFind(isNull: ['category']);
		$results = $statement->fetchAll(PDO::FETCH_CLASS, TestModel::class);
		
		return count($results) === 1
			&& $results[0]->name === 'Zeta';
	}
	
	public function isNotNull(): bool
	{
		$statement = $this->store->executeFind(isNotNull: ['category']);
		$results = $statement->fetchAll(PDO::FETCH_CLASS, TestModel::class);
		
		return count($results) === 5;
	}
	
	public function like(): bool
	{
		$statement = $this->store->executeFind(
			like: ['name' => '%psilon'],
			query: fn($q) => $q->orderBy('id ASC'),
		);
		$results = $statement->fetchAll(PDO::FETCH_ASSOC);
		
		return count($results) === 1
			&& $results[0]['name'] === 'Epsilon';
	}
	
	public function notLike(): bool
	{
		$statement = $this->store->executeFind(notLike: [
			'name' => '%psilon',
		]);
		$results = $statement->fetchAll(PDO::FETCH_ASSOC);
		
		return count($results) === 4;
	}
	
	public function queryCallback(): bool
	{
		$statement = $this->store->executeFind(
			where: ['active' => 1],
			query: fn($q) => $q->orderBy('name ASC')->limit(2),
		);
		$results = $statement->fetchAll(PDO::FETCH_CLASS, TestModel::class);
		
		return count($results) === 2
			&& $results[0]->name === 'Alpha'
			&& $results[1]->name === 'Beta';
	}
	
	public function queryCallbackOrderBy(): bool
	{
		$statement = $this->store->executeFind(
			query: fn($q) => $q->orderBy('id DESC'),
		);
		$results = $statement->fetchAll(PDO::FETCH_CLASS, TestModel::class);
		
		return count($results) === 5
			&& $results[0]->name === 'Epsilon'
			&& $results[4]->name === 'Alpha';
	}
	
	public function queryCallbackGroupBy(): bool
	{
		$statement = $this->store->executeFind(
			select: 'category, COUNT(*) as total',
			query: fn($q) => $q->groupBy('category')->orderBy('category ASC'),
		);
		$results = $statement->fetchAll(PDO::FETCH_ASSOC);
		
		return count($results) === 3
			&& $results[0]['category'] === 'fruit'
			&& (int) $results[0]['total'] === 2;
	}
	
	public function combined(): bool
	{
		$statement = $this->store->executeFind(
			where: ['active' => 1],
			whereIn: ['id' => [1, 2, 4]],
			query: fn($q) => $q->orderBy('name DESC'),
		);
		$results = $statement->fetchAll(PDO::FETCH_CLASS, TestModel::class);
		
		return count($results) === 3
			&& $results[0]->name === 'Delta'
			&& $results[2]->name === 'Alpha';
	}
	
	public function findMany(): bool
	{
		$results = $this->store->find(
			where: ['active' => 1],
			query: fn($q) => $q->orderBy('id ASC'),
			many: true,
		);
		
		return count($results) === 3
			&& $results[0] instanceof TestModel
			&& $results[0]->name === 'Alpha';
	}
	
	public function findOne(): bool
	{
		$result = $this->store->find(where: ['id' => 1]);
		
		return $result instanceof TestModel
			&& $result->name === 'Alpha';
	}
	
	/**
	 * RULE: INSERT and UPDATE columns are PHP values bound by position with
	 * their types (a false stays 0, a null NULL) and an Expression is SQL;
	 * row() writes many at once, affected() counts, the fetchers read back.
	 */
	public function insertUpdateValues(): bool
	{
		$store = $this->store;
		$inserted = $store->affected($store->query()
			->insert(id: 10, name: 'Eta', category: new Expression('LOWER("FRUIT")'), active: false)
			->row(id: 11, name: 'Theta', category: null, active: true));
		
		$rows = $store->fetchModels($store->query()
			->select('*')
			->whereIn('id', [10, 11], bind: true)
			->orderBy('id ASC'));
		
		$updated = $store->affected($store->query()
			->update(name: 'Iota', active: new Expression('1 - active'))
			->where(['id = ?', 10]));
		$eta = $store->fetchModel($store->query()
			->select('*')
			->where(['id = ?', 10]));
		
		return $inserted === 2
			&& count($rows) === 2
			&& $rows[0]->name === 'Eta'
			&& $rows[0]->category === 'fruit'
			&& (int)$rows[0]->active === 0
			&& $rows[1]->name === 'Theta'
			&& $rows[1]->category === null
			&& (int)$rows[1]->active === 1
			&& $updated === 1
			&& $eta instanceof TestModel
			&& $eta->name === 'Iota'
			&& (int)$eta->active === 1;
	}
	
	/**
	 * Called by the runner after each test method
	 */
	#[Internal]
	#[Override]
	public function finalize(): void
	{
		$this->store->source()
			->exec('TRUNCATE TABLE tests_find');
		
		$this->store->source()->exec('
			INSERT INTO tests_find (id, name, category, active)
			VALUES
				(1, "Alpha", "fruit", 1),
				(2, "Beta", "vegetable", 1),
				(3, "Gamma", "fruit", 0),
				(4, "Delta", "grain", 1),
				(5, "Epsilon", "vegetable", 0);
		');
	}
	
	/**
	 * Called by the runner after all test methods have been invoked
	 */
	#[Internal]
	#[Override]
	public function deconstruct(): void
	{
		$this->store->source()
			->exec('DROP TABLE IF EXISTS tests_find');
	}
}

class TestStore extends Store
{
	use Find;
	
	public const ?string TABLE = 'tests_find';
	public const ?string MODEL = TestModel::class;
}

class TestModel extends Model
{
	public ?int $id = null;
	public ?string $name = null;
	public ?string $category = null;
	public ?int $active = null;
	
	public static function getStoreClass(): string
	{
		return TestStore::class;
	}
}