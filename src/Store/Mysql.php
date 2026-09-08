<?php
declare(strict_types=1);

namespace Ovos\Store;

use Ovos\Exception;
use Ovos\Connections;
use Ovos\Model\Mysql as Model;
use Ovos\Model\Relation\Many as RelationMany;
use Ovos\Model\Relation\One as RelationOne;
use Ovos\Model\Relations;
use Ovos\Store;
use Ovos\Store\Mysql\Query;
use Ovos\Store\Mysql\Query\Select;
use Ovos\Store\Mysql\QueryBuilder;
use Ovos\Pdo\Expression;
use PDO;
use PDOStatement;
use Closure;

use function array_column;
use function array_combine;
use function array_keys;
use function array_map;
use function array_unique;
use function array_values;
use function count;
use function get_class;
use function implode;
use function is_bool;
use function preg_replace;
use function range;
use function reset;
use function sprintf;

/**
 * Mysql
 *
 * @author Marcin Gil <mg@ovos.at>
 */
abstract class Mysql extends Store
{
	/**
	 * Primary table name
	 */
	public const ?string TABLE = null;
	
	/**
	 * Related model name
	 */
	public const ?string MODEL = null;
	
	protected string $sourceName = 'mysql';
	
	/* Some properties use undescores for consistency with Model class */
	
	/**
	 * A connection between PHP and a database server
	 */
	protected ?PDO $_source = null;
	
	public function getSource(): ?PDO
	{
		if($this->_source === null)
		{
			// get database connection
			$this->_source = $this->container
				->getClass(Connections::class)
				->get($this->sourceName)
				->getClient();
		}
		
		return $this->_source;
	}
	
	/**
	 * Short for getSource
	 */
	public function source(): ?PDO
	{
		return $this->getSource();
	}
	
	public static function getTable(): string
	{
		if(static::TABLE === null)
		{
			throw new Exception('Store is required to have a non-null table name.');
		}
		
		return static::TABLE;
	}
	
	public static function getModel(): string
	{
		if(static::MODEL === null)
		{
			throw new Exception('Store is required to have a non-null model name.');
		}
		
		return static::MODEL;
	}
	
	/**
	 * A builder for this store's table. The query it makes carries its values
	 * (tuple conditions, PHP values for INSERT/UPDATE columns); statement() and
	 * the fetchers run it with them, prepareQuery() hands back the bare prepared
	 * statement.
	 */
	public function query(): QueryBuilder
	{
		return new QueryBuilder(static::TABLE);
	}
	
	public function prepareQuery(
		Query $query,
	): false|PDOStatement
	{
		return $this->getSource()
			->prepare($query->getSql());
	}
	
	/**
	 * Rows affected. A query carrying values is prepared and executed with
	 * them (PDO::exec cannot bind).
	 */
	public function executeQuery(
		Query $query,
	): false|int
	{
		if($query->getValues() !== [])
		{
			return $this->statement($query)?->rowCount() ?? false;
		}
		
		return $this->getSource()
			->exec($query->getSql());
	}
	
	/**
	 * The executed statement. A query carrying values is prepared and
	 * executed with them (PDO::query cannot bind).
	 */
	public function runQuery(
		Query $query,
	): false|PDOStatement
	{
		if($query->getValues() !== [])
		{
			return $this->statement($query) ?? false;
		}
		
		return $this->getSource()
			->query($query->getSql());
	}
	
	/**
	 * The query prepared, its values bound by position with their PHP types,
	 * executed; null without a
	 * source (the connection failed). The fetchers below answer their empty
	 * shape in that case, so a read on a down database answers nothing and
	 * a write touches nothing.
	 */
	public function statement(
		Query $query,
	): ?PDOStatement
	{
		$source = $this->getSource();
		if($source === null)
		{
			return null;
		}
		
		$statement = $source->prepare($query->getSql());
		$values = $query->getValues();
		if($values !== [])
		{
			// by position, typed: an int stays an int, a bool a bool, a null NULL
			$this->bindValues($statement, array_combine(range(1, count($values)), $values));
		}
		$statement->execute();
		
		return $statement;
	}
	
	/**
	 * @return Model[] this store's MODEL instances
	 */
	public function fetchModels(
		Select $query,
	): array
	{
		return $this->statement($query)
			?->fetchAll(PDO::FETCH_CLASS, static::getModel()) ?? [];
	}
	
	/**
	 * The first row as this store's MODEL, null when there is none
	 */
	public function fetchModel(
		Select $query,
	): ?Model
	{
		$model = $this->statement($query)
			?->fetchObject(static::getModel());
		
		return $model instanceof Model ? $model : null;
	}
	
	/**
	 * @return list<array<string, mixed>>
	 */
	public function fetchRows(
		Select $query,
	): array
	{
		return $this->statement($query)
			?->fetchAll(PDO::FETCH_ASSOC) ?? [];
	}
	
	/**
	 * @return list<mixed> the first column of every row
	 */
	public function fetchList(
		Select $query,
	): array
	{
		return $this->statement($query)
			?->fetchAll(PDO::FETCH_COLUMN) ?? [];
	}
	
	/**
	 * The first column of the first row; false when there is none
	 */
	public function fetchScalar(
		Select $query,
	): mixed
	{
		return $this->statement($query)
			?->fetchColumn() ?? false;
	}
	
	/**
	 * The rows a write touched
	 */
	public function affected(
		Query $query,
	): int
	{
		return $this->statement($query)
			?->rowCount() ?? 0;
	}
	
	public function tableExists(): bool
	{
		return $this->getSource()->query('
			SHOW TABLES LIKE "' . self::getTable() . '"
		')->fetch(PDO::FETCH_NUM) !== false;
	}
	
	public function optimize(): bool
	{
		return $this->getSource()->query('
			OPTIMIZE
			TABLE ' . self::getTable() . '
		')->closeCursor();
	}
	
	public function insertQuery(
		Model $object,
	): false|PDOStatement
	{
		$values = $this->getQueryValues($object);
		
		$sql = 'INSERT INTO ' . self::getTable() . ' (%s) VALUES (%s);';
		$sql = sprintf($sql,
			implode(', ', array_keys($values)),
			implode(', ', $values)
		);
		
		$statement = $this->getSource()->prepare($sql);
		$this->bindValues($statement, $object);
		
		return $statement;
	}
	
	public function updateQuery(
		Model $model,
		Model $updateObject,
	): false|PDOStatement
	{
		$sets = $this->getQueryValues($updateObject, true);
		
		$sql = 'UPDATE ' . self::getTable() . ' SET %s'
			. ' WHERE ' . $model->getPrimaryKeysConditions();
		$sql = sprintf($sql, implode(', ', $sets));
		
		$statement = $this->getSource()->prepare($sql);
		$model->bindPrimaryKeys($statement);
		$this->bindValues($statement, $updateObject);
		
		return $statement;
	}
	
	public function getQueryValues(
		Model|array $fields,
		bool $sets = false,
	): array
	{
		$values = [];
		
		foreach($fields as $field => $value)
		{
			if($value instanceof Expression)
			{
				$values[$field] = $value->__toString();
			}
			else
			{
				$values[$field] = ':' . $field;
			}
			
			if($sets)
			{
				$values[$field] = $field . ' = ' . $values[$field];
			}
		}
		
		return $values;
	}
	
	public function bindValues(
		PDOStatement $statement,
		Model|array $fields,
	): void
	{
		foreach($fields as $field => $value)
		{
			if($value instanceof Expression)
			{
				continue;
			}
			
			$bindType = PDO::PARAM_STR;
			$bindType = is_bool($value) ? PDO::PARAM_BOOL : $bindType;
			$bindType = is_int($value) ? PDO::PARAM_INT : $bindType;
			
			// ':' prefix is optional, $field can be also numerical, starting from 1
			$statement->bindValue($field, $value, $bindType);
		}
	}
	
	public function update(
		Model $object,
		Model $objectUpdate,
	): bool
	{
		return $object->update($objectUpdate);
	}
	
	public function insert(
		Model $model,
	): bool
	{
		return $model->insert();
	}
	
	/**
	 * The PHP values given are bound, never written into the SQL: an IN list
	 * of names works as well as one of ids
	 */
	public function executeFind(
		array $where = [],
		string $select = '*',
		array $orWhere = [],
		array $whereIn = [],
		array $whereNotIn = [],
		array $isNull = [],
		array $isNotNull = [],
		array $like = [],
		array $notLike = [],
		?callable $query = null,
	): false|PDOStatement
	{
		$select = $this->query()
			->select($select);
		
		foreach($where as $property => $value)
		{
			$select->andWhere([$property . ' = ?', $value]);
		}
		
		$orConditions = [];
		$orValues = [];
		foreach($orWhere as $property => $value)
		{
			$orConditions[] = $property . ' = ?';
			$orValues[] = $value;
		}
		if($orConditions)
		{
			$select->andWhere(['(' . implode(' OR ', $orConditions) . ')', ...$orValues]);
		}
		
		foreach($whereIn as $property => $whereValues)
		{
			$select->andWhereIn($property, $whereValues, bind: true);
		}
		foreach($whereNotIn as $property => $whereValues)
		{
			$select->andWhereNotIn($property, $whereValues, bind: true);
		}
		
		foreach($like as $property => $value)
		{
			$select->andWhere([$property . ' LIKE ?', $value]);
		}
		foreach($notLike as $property => $value)
		{
			$select->andWhere([$property . ' NOT LIKE ?', $value]);
		}
		
		foreach($isNull as $property)
		{
			$select->andWhere($property . ' IS NULL');
		}
		foreach($isNotNull as $property)
		{
			$select->andWhere($property . ' IS NOT NULL');
		}
		
		if($query !== null)
		{
			$query($select);
		}
		
		return $this->statement($select) ?? false;
	}
	
	public function fetchGrouped(
		PDOStatement $statement,
		string $class,
	): array
	{
		$result = $statement
			->fetchAll(PDO::FETCH_CLASS | PDO::FETCH_GROUP, $class); // group by the first column
		return array_map(static fn($row) => reset($row), $result);
	}
	
	public function fetchReferenced(
		array $referenced,
		string $referencedBy,
		string $class,
		?Closure $queryCallback = null,
		string $groupBy = 'id',
	): array
	{
		$ids = array_values(array_unique(array_column($referenced, $referencedBy)));
		if(empty($ids))
		{
			return [];
		}
		
		$query = $this->query()
			->select($groupBy . ', ' . static::TABLE . '.*')
			->whereIn($groupBy, $ids, bind: true);
		if($queryCallback)
		{
			$queryCallback($query);
		}
		
		$statement = $this->statement($query);
		if($statement === null)
		{
			return [];
		}
		
		return $this->fetchGrouped($statement, $class);
	}
	
	public function fetchByReference(
		array $referenced,
		string $referencedBy,
		string $class,
		?Closure $queryCallback = null,
	): array
	{
		$ids = array_values(array_keys($referenced));
		if(empty($ids))
		{
			return [];
		}
		
		$query = $this->query()
			->select(static::TABLE . '.*')
			->whereIn($referencedBy, $ids, bind: true);
		if($queryCallback)
		{
			$queryCallback($query);
		}
		
		return $this->statement($query)
			?->fetchAll(PDO::FETCH_CLASS, $class) ?? [];
	}
	
	public function assignByReference(
		array $referenced,
		string $referencedBy,
		string $reference,
		array $items,
		string $key,
	): array
	{
		foreach($items as $item)
		{
			if(isset($referenced[$item->$referencedBy]) === false)
			{
				continue;
			}
			
			$model = $referenced[$item->$referencedBy];
			/**
			 * @var Model $model
			 */
			if($model->hasReference($reference) === false)
			{
				$model->reference($reference, []);
			}
			
			$model->getReference($reference)[$item->$key] = $item;
		}
		
		return $referenced;
	}
	
	public function assignReferenced(
		array $referenced,
		string $referencedBy,
		string $reference,
		array $items,
	): array
	{
		foreach($referenced as $model)
		{
			if(isset($items[$model->$referencedBy]) === false)
			{
				continue;
			}
			
			$model->reference($reference, $items[$model->$referencedBy]);
		}
		
		return $referenced;
	}
	
	/**
	 * Batch-loads DECLARED relations onto a set of models keyed by id
	 * (the fetchGrouped shape) - one IN query per relation, generated
	 * from the model's Relation attributes; the hand-written
	 * referenceByX() boilerplate, retired.
	 *
	 * Every parent ends up with the reference SET (an empty array for
	 * Many, null for One, when nothing matched) - a loaded-empty
	 * reference is distinguishable from a never-loaded one, which is
	 * exactly what the model's lazy fallback keys on.
	 */
	public function withRelations(
		array $items,
		?array $names = null, // null = every declared relation
		array $overrides = [], // [name => Closure] applied after the scope
	): array
	{
		if($items === [])
		{
			return $items;
		}
		
		$class = get_class(reset($items));
		
		foreach($names ?? array_keys(Relations::forClass($class)) as $name)
		{
			$relation = Relations::require($class, $name);
			$store = new ($relation->storeClass());
			$callback = Relations::callback($store, $relation,
				$overrides[$name] ?? null);
			
			if($relation instanceof RelationMany)
			{
				$children = $store->fetchByReference($items,
					$relation->by, $relation->model, $callback);
				$this->assignByReference($items, $relation->by,
					$name, $children, $relation->key);
				
				foreach($items as $item)
				{
					if($item->hasReference($name) === false)
					{
						$item->setReference($name, []);
					}
				}
				
				continue;
			}
			
			/** @var RelationOne $relation */
			$ids = [];
			foreach($items as $item)
			{
				if($item->{$relation->on} !== null)
				{
					$ids[$item->{$relation->on}] = true;
				}
			}
			
			$keyed = [];
			foreach($store->fetchByReference($ids,
				$relation->key, $relation->model, $callback) as $child)
			{
				$keyed[$child->{$relation->key}] = $child;
			}
			
			foreach($items as $item)
			{
				// a null fk is a loaded null, never an array offset
				$foreign = $item->{$relation->on};
				$item->setReference($name,
					$foreign !== null ? ($keyed[$foreign] ?? null) : null);
			}
		}
		
		return $items;
	}
	
	/**
	 * Removed all characters that can break AGAINST (... IN BOOLEAN MODE) queries
	 */
	public function sanitizeForBooleanQuery(
		string $query,
	): string
	{
		return preg_replace('~[^\w ]~u', '', $query);
	}
}
