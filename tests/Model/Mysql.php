<?php
declare(strict_types=1);

namespace Tests\Model;

use Ovos\Test;
use Ovos\Test\Internal;
use Ovos\Model\Mysql as Model;
use Ovos\Store\Mysql as Store;
use Ovos\Model\Mysql\Template;
use Override;

use function array_key_exists;

/**
 * Mysql
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Mysql extends Test
{
	protected object $store;
	
	protected object $model;
	
	protected object $recorder;
	
	public function __construct()
	{
		$this->store = (new class() extends Store
		{
			public const ?string TABLE = 'tests';
		});
		$this->model = new class() extends Model
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
		$this->model::$store = $this->store;
		
		// records which lifecycle events fired, to assert the post* hooks
		$this->recorder = new class() extends Model
		{
			/** @var string[] */
			public array $events = [];
			
			public static object $store;
			
			public static function getStoreClass(): string
			{
				return self::$store::class;
			}
			
			public function postInsert(): void
			{
				$this->events[] = 'postInsert';
			}
			
			public function postUpdate(): void
			{
				$this->events[] = 'postUpdate';
			}
			
			public function postSave(): void
			{
				$this->events[] = 'postSave';
			}
			
			public function postDelete(): void
			{
				$this->events[] = 'postDelete';
			}
		};
		$this->recorder::$store = $this->store;
		
		$this->store->source()->exec('
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
		$this->store->source()->exec('
			INSERT INTO tests
			VALUES
				(1, "Test 1", null, null, 1);
		');
		
		$query = $this->store->executeFind(where: ['id' => 1]);
		/** @var Model $model */
		$model = $query->fetchObject($this->model::class);
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
		$this->store->source()->exec('
			INSERT INTO tests
			VALUES
				(1, "Test 1", "{\\"test\\": 1}", null, 1);
		');
		
		$query = $this->store->executeFind(where: ['id' => 1]);
		/** @var Model $model */
		$model = $query->fetchObject($this->model::class);
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
		$this->store->source()->exec('
			INSERT INTO tests
			VALUES
				(1, "Test 1", null, null, 1);
		');
		
		$query = $this->store->executeFind(where: ['id' => 1]);
		/** @var Model $model */
		$model = $query->fetchObject($this->model::class);
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
		$this->store->source()->exec('
			INSERT INTO tests
			VALUES
				(1, "Test 1", null, null, 1);
		');
		
		$query = $this->store->executeFind(where: ['id' => 1]);
		/** @var Model $model */
		$model = $query->fetchObject($this->model::class);
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
		$model = $this->model::import([
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
		$model = new $this->model;
		$model->save();
		
		return $model->id === 1;
	}
	
	/**
	 * An insert fires postInsert then postSave (in that order).
	 */
	public function postInsertFiresInsertAndSave(): bool
	{
		$model = new $this->recorder;
		$model->save();
		
		return $model->events === ['postInsert', 'postSave'];
	}
	
	/**
	 * A modifying save fires postUpdate then postSave (not postInsert).
	 */
	public function postUpdateFiresUpdateAndSave(): bool
	{
		$this->store->source()->exec('
			INSERT INTO tests
			VALUES
				(1, "Test 1", null, null, 1);
		');
		
		$query = $this->store->executeFind(where: ['id' => 1]);
		/** @var Model $model */
		$model = $query->fetchObject($this->recorder::class);
		$model->name = 'Test modified';
		$model->save();
		
		return $model->events === ['postUpdate', 'postSave'];
	}
	
	/**
	 * Saving an unmodified model is a no-op, so no post* event fires.
	 */
	public function postSaveSkippedWhenUnmodified(): bool
	{
		$this->store->source()->exec('
			INSERT INTO tests
			VALUES
				(1, "Test 1", null, null, 1);
		');
		
		$query = $this->store->executeFind(where: ['id' => 1]);
		/** @var Model $model */
		$model = $query->fetchObject($this->recorder::class);
		$saved = $model->save();
		
		return $saved === false && $model->events === [];
	}
	
	/**
	 * A delete fires postDelete.
	 */
	public function postDeleteFiresDelete(): bool
	{
		$this->store->source()->exec('
			INSERT INTO tests
			VALUES
				(1, "Test 1", null, null, 1);
		');
		
		$query = $this->store->executeFind(where: ['id' => 1]);
		/** @var Model $model */
		$model = $query->fetchObject($this->recorder::class);
		$model->delete();
		
		return $model->events === ['postDelete'];
	}
	
	/**
	 * __serialize must strip the injected dependencies inherited
	 * from the base Model (container, app, config).
	 */
	public function serializeStripsInjected(): bool
	{
		/** @var Model $model */
		$model = new $this->model;
		$serialized = $model->__serialize();
		
		return array_key_exists('container', $serialized) === false
			&& array_key_exists('app', $serialized) === false
			&& array_key_exists('config', $serialized) === false;
	}
	
	/**
	 * __serialize must strip the PDO connection (_source), otherwise
	 * the serialized payload pulls in an unserializable resource.
	 */
	public function serializeStripsSource(): bool
	{
		/** @var Model $model */
		$model = new $this->model;
		// force _source to be populated with a PDO connection
		$model->source();
		
		$serialized = $model->__serialize();
		
		return array_key_exists('_source', $serialized) === false;
	}
	
	/**
	 * Regression test: full __serialize -> __unserialize round-trip
	 * on a Mysql model must preserve _properties, _modified state
	 * and re-inject the dependencies. Without the bug-fix the
	 * rehydrated model was empty.
	 */
	public function serializeUnserializeRoundtrip(): bool
	{
		$this->store->source()->exec('
			INSERT INTO tests
			VALUES
				(1, "Test 1", null, null, 1);
		');
		
		$query = $this->store->executeFind(where: ['id' => 1]);
		/** @var Model $model */
		$model = $query->fetchObject($this->model::class);
		$model->name = 'Test modified';
		
		$serialized = $model->__serialize();
		
		/** @var Model $rehydrated */
		$rehydrated = new $this->model;
		$rehydrated->__unserialize($serialized);
		
		$properties = $rehydrated->getProperties();
		
		return $properties['id'] === 1
			&& $properties['name'] === 'Test modified'
			&& $rehydrated->isModified('name')
			&& $rehydrated->exists();
	}
	
	/**
	 * Called by the runner after each test method
	 */
	#[Internal]
	#[Override]
	public function finalize(): void
	{
		$this->store->source()
			->exec('TRUNCATE TABLE tests');
	}
	
	/**
	 * Called by the runner after all test methods have been invoked
	 */
	#[Internal]
	#[Override]
	public function deconstruct(): void
	{
		$this->store->source()
			->exec('DROP TABLE IF EXISTS tests');
	}
}
