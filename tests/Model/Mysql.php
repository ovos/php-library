<?php
declare(strict_types=1);

namespace Tests\Model;

use Ovos\Exception;
use Ovos\Pdo\Expression;
use Ovos\Test;
use Ovos\Test\Internal;
use Ovos\Model\Mysql as Model;
use Ovos\Store\Mysql as Store;
use Ovos\Model\Mysql\Template;
use PDO;

/**
 * Mysql
 *
 * @package Tests
 * @author Marcin Gil <mg@ovos.at>
 */
class Mysql extends Test
{
	/**
	 * @var object
	 */
	protected object $_store;
	
	/**
	 * @var object
	 */
	protected object $_model; 
	
	public function __construct()
	{
		$this->_store = (new class() extends Store
		{
			public const ?string TABLE = 'tests';
		});
		$this->_model = new class() extends Model
		{
			public static object $store;
			
			public static function getStoreClass(): string
			{
				return self::$store::class;
			}
			
			public function setUp(): void
			{
				$this->addTemplate(new Template\Json(['object'], Template\Json::TYPE_ARRAY));
			}
		};
		$this->_model::$store = $this->_store;
		
		$this->_store->source()->exec('
			CREATE TABLE IF NOT EXISTS tests (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				name VARCHAR(128) NULL,
				object JSON NULL,
				modified_at DATETIME NULL,
				active TINYINT UNSIGNED NOT NULL DEFAULT 1,
				PRIMARY KEY (id),
				INDEX modified_at (modified_at ASC),
				INDEX active (active ASC)
			)
			ENGINE = InnoDB;
		');
	}
	
	public function modified(): bool
	{
		$this->_store->source()->exec('
			INSERT INTO tests
			VALUES
				(1, "Test 1", null, null, 1);
		');
		
		$query = $this->_store->executeFind(where: ['id' => 1]);
		/** @var Model $model */
		$model = $query->fetchObject($this->_model::class);
		$model->name = 'Test modified';
		$model->object = ['test' => 1, 'test2' => 2];
		$modified = $model->getModified();
		
		$compare = [
			'name' => 'Test modified',
			'object' => '{"test": 1, "test2": 2}',
		];
		
		return count(array_diff($modified, $compare)) === 0;
	}
	
	public function export(): bool
	{
		$this->_store->source()->exec('
			INSERT INTO tests
			VALUES
				(1, "Test 1", "{\\"test\\": 1}", null, 1);
		');
		
		$query = $this->_store->executeFind(where: ['id' => 1]);
		/** @var Model $model */
		$model = $query->fetchObject($this->_model::class);
		$export = $model->export(type: Model::EXPORT_TYPE_ARRAY);
		
		$export['object'] = serialize($export['object']);
		$compare = [
			'id' => 1,
			'name' => 'Test 1',
			'object' => 'a:1:{s:4:"test";i:1;}',
			'modified_at' => null,
			'active' => 1,
		];
		
		return count(array_diff($export, $compare)) === 0;
	}
	
	public function filterIn(): bool
	{
		$this->_store->source()->exec('
			INSERT INTO tests
			VALUES
				(1, "Test 1", null, null, 1);
		');
		
		$query = $this->_store->executeFind(where: ['id' => 1]);
		/** @var Model $model */
		$model = $query->fetchObject($this->_model::class);
		$export = $model->export(
			type: Model::EXPORT_TYPE_ARRAY,
			filter: ['name'],
			filterMode: Model::FILTER_MODE_IN,
		);
		
		$compare = [
			'name' => 'Test 1',
		];
		
		return count(array_diff($export, $compare)) === 0;
	}
	
	public function filterOut(): bool
	{
		$this->_store->source()->exec('
			INSERT INTO tests
			VALUES
				(1, "Test 1", null, null, 1);
		');
		
		$query = $this->_store->executeFind(where: ['id' => 1]);
		/** @var Model $model */
		$model = $query->fetchObject($this->_model::class);
		$export = $model->export(
			type: Model::EXPORT_TYPE_ARRAY,
			filter: ['name'],
			filterMode: Model::FILTER_MODE_OUT,
		);
		
		$compare = [
			'id' => 1,
			'object' => null,
			'modified_at' => null,
			'active' => 1,
		];
		
		return count(array_diff($export, $compare)) === 0;
	}
	
	public function import(): bool
	{
		/** @var Model $model */
		$model = $this->_model::import([
			'name' => 'Test 1',
			'object' => [],
			'modified_at' => '2000-01-01 01:01:01',
		]);
		
		return $model->name === 'Test 1'
			&& is_array($model->object)
			&& $model->modified_at === '2000-01-01 01:01:01';
	}
	
	public function save(): bool
	{
		/** @var Model $model */
		$model = new $this->_model;
		$model->save();
		
		return $model->id === 1;
	}
	
	/**
	 * Called by the runner after each test method
	 */
	#[Internal]
	public function finalize(): void
	{
		$this->_store->source()->exec('TRUNCATE TABLE tests');
	}
	
	/**
	 * Called by the runner after all test methods have been invoked
	 */
	#[Internal]
	public function deconstruct(): void
	{
		$this->_store->source()->exec('DROP TABLE IF EXISTS tests');
	}
}
