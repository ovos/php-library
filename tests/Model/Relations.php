<?php
declare(strict_types=1);

namespace Tests\Model;

use Ovos\Exception;
use Ovos\Model\Mysql as Model;
use Ovos\Model\Relation\Many;
use Ovos\Model\Relation\One;
use Ovos\Model\Relations as Subject;
use Ovos\Store\Mysql as Store;
use Ovos\Test;
use Ovos\Test\Internal;
use Override;
use PDO;

use function array_keys;
use function count;

/**
 * Relations
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Relations extends Test
{
	protected object $parents;
	
	public function __construct()
	{
		$this->parents = new RelParents;
		
		$source = $this->parents->source();
		$source->exec('
			CREATE TABLE IF NOT EXISTS tests_rel_parents (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				category_id BIGINT UNSIGNED NULL,
				PRIMARY KEY (id)
			)
			ENGINE = InnoDB;
		');
		$source->exec('
			CREATE TABLE IF NOT EXISTS tests_rel_children (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				parent_id BIGINT UNSIGNED NOT NULL,
				skill_id BIGINT UNSIGNED NOT NULL,
				active TINYINT UNSIGNED NOT NULL DEFAULT 1,
				PRIMARY KEY (id)
			)
			ENGINE = InnoDB;
		');
		$source->exec('
			CREATE TABLE IF NOT EXISTS tests_rel_categories (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				name VARCHAR(64) NULL,
				PRIMARY KEY (id)
			)
			ENGINE = InnoDB;
		');
		
		$source->exec('
			INSERT INTO tests_rel_parents (id, category_id) VALUES
				(1, 10), (2, NULL), (3, 10);
		');
		$source->exec('
			INSERT INTO tests_rel_children (id, parent_id, skill_id, active) VALUES
				(1, 1, 100, 1),
				(2, 1, 200, 1),
				(3, 1, 300, 0),
				(4, 3, 100, 1);
		');
		$source->exec('
			INSERT INTO tests_rel_categories (id, name) VALUES
				(10, "Trades");
		');
	}
	
	/**
	 * Models keyed by id - the fetchGrouped shape withRelations() takes
	 *
	 * @return array<int, RelParent>
	 */
	protected function fetchParents(): array
	{
		$statement = $this->parents->source()
			->query('SELECT * FROM tests_rel_parents ORDER BY id');
			
		$items = [];
		foreach($statement->fetchAll(PDO::FETCH_CLASS, RelParent::class) as $parent)
		{
			$items[$parent->id] = $parent;
		}
		
		return $items;
	}
	
	public function declarationsAreReadFromAttributes(): bool
	{
		$relations = Subject::forClass(RelParent::class);
		
		$unknown = false;
		try
		{
			Subject::require(RelParent::class, 'Nope');
		}
		catch(Exception)
		{
			$unknown = true;
		}
		
		return array_keys($relations) === ['Skills', 'AllSkills', 'Category']
			&& Subject::get(RelParent::class, 'Skills') instanceof Many
			&& Subject::get(RelParent::class, 'Category') instanceof One
			&& $unknown === true;
	}
	
	public function withRelationsBatchesMany(): bool
	{
		$items = $this->parents
			->withRelations($this->fetchParents(), ['Skills']);
			
		$skills = $items[1]->Skills;
		
		return array_keys($skills) === [100, 200] // scope filtered active=0
			&& $skills[100] instanceof RelChild
			&& $items[3]->Skills[100]->parent_id === 3
			// the childless parent is LOADED-empty, not never-loaded
			&& $items[2]->Skills === []
			&& $items[2]->hasReference('Skills') === true;
	}
	
	public function withRelationsAssignsOne(): bool
	{
		$items = $this->parents
			->withRelations($this->fetchParents(), ['Category']);
			
		return $items[1]->Category instanceof RelCategory
			&& $items[1]->Category->name === 'Trades'
			&& $items[3]->Category === $items[1]->Category
			// a null fk still marks the reference as loaded
			&& $items[2]->Category === null
			&& $items[2]->hasReference('Category') === true;
	}
	
	public function overridesApplyAfterTheScope(): bool
	{
		$items = $this->parents->withRelations($this->fetchParents(),
			['Skills'], [
				'Skills' => function(object $query): void
				{
					$query->andWhere('skill_id = 100');
				},
			]);
			
		return array_keys($items[1]->Skills) === [100]
			&& $items[3]->Skills !== [];
	}
	
	public function lazyLoadsOnFirstAccess(): bool
	{
		$items = $this->fetchParents();
		$parent = $items[1];
		
		$before = $parent->hasReference('Skills');
		$skills = $parent->Skills; // triggers the lazy load
		
		return $before === false
			&& array_keys($skills) === [100, 200]
			&& $parent->hasReference('Skills') === true;
	}
	
	public function lazyLoadedEmptyDoesNotRetrigger(): bool
	{
		$items = $this->fetchParents();
		$parent = $items[2]; // no children
		
		$first = $parent->Skills;
		$loaded = $parent->hasReference('Skills');
		$second = $parent->Skills;
		
		return $first === []
			&& $loaded === true
			&& $second === [];
	}
	
	public function lazyFalseStaysUnloaded(): bool
	{
		$items = $this->fetchParents();
		$parent = $items[1];
		
		return $parent->AllSkills === null
			&& $parent->hasReference('AllSkills') === false;
	}
	
	public function lazyLoadsOneRelations(): bool
	{
		$items = $this->fetchParents();
		
		return $items[1]->Category instanceof RelCategory
			&& $items[2]->Category === null;
	}
	
	/**
	 * Called by the runner after all test methods
	 */
	#[Internal]
	#[Override]
	public function deconstruct(): void
	{
		$source = $this->parents->source();
		$source->exec('DROP TABLE IF EXISTS tests_rel_parents');
		$source->exec('DROP TABLE IF EXISTS tests_rel_children');
		$source->exec('DROP TABLE IF EXISTS tests_rel_categories');
	}
}

class RelParents extends Store
{
	public const ?string TABLE = 'tests_rel_parents';
	public const ?string MODEL = RelParent::class;
}

class RelChildren extends Store
{
	public const ?string TABLE = 'tests_rel_children';
	public const ?string MODEL = RelChild::class;
	
	public function scopeActive(
		object $query,
	): void
	{
		$query->andWhere('active = 1');
	}
}

class RelCategories extends Store
{
	public const ?string TABLE = 'tests_rel_categories';
	public const ?string MODEL = RelCategory::class;
}

#[Many('Skills', RelChild::class,
	by: 'parent_id', key: 'skill_id',
	store: RelChildren::class, scope: 'active')]
#[Many('AllSkills', RelChild::class,
	by: 'parent_id', key: 'id',
	store: RelChildren::class, lazy: false)]
#[One('Category', RelCategory::class,
	on: 'category_id')] // store defaults to the child model's own
class RelParent extends Model
{
	public ?int $id = null;
	public ?int $category_id = null;

	public static function getStoreClass(): string
	{
		return RelParents::class;
	}
}

class RelChild extends Model
{
	public ?int $id = null;
	public ?int $parent_id = null;
	public ?int $skill_id = null;
	public ?int $active = null;

	public static function getStoreClass(): string
	{
		return RelChildren::class;
	}
}

class RelCategory extends Model
{
	public ?int $id = null;
	public ?string $name = null;

	public static function getStoreClass(): string
	{
		return RelCategories::class;
	}
}
