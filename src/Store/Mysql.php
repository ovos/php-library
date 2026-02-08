<?php
declare(strict_types=1);

namespace Ovos\Store;

use Ovos\Exception;
use Ovos\Connections;
use Ovos\Model\Mysql as Model;
use Ovos\Store;
use Ovos\Store\Mysql\Query;
use Ovos\Store\Mysql\QueryBuilder;
use Ovos\Pdo\Expression;
use PDO;
use PDOStatement;
use Closure;

use function array_column;
use function array_keys;
use function array_map;
use function array_unique;
use function implode;
use function is_bool;
use function preg_replace;
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
	 * Only used for getSql() calls, never used to query the database
	 * or fetch results
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
	
	public function executeQuery(
		Query $query,
	): false|int
	{
		return $this->getSource()
			->exec($query->getSql());
	}
	
	public function runQuery(
		Query $query,
	): false|PDOStatement
	{
		return $this->getSource()
			->query($query->getSql());
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
	
	/**
	 * @deprecated
	 */
	/*
	public function insertUpdateQuery(Model $object, array $conditions = [])
	{
		$where = $this->getQueryValues($conditions);
		$values = $this->getQueryValues($object);
		
		$sql = 'INSERT INTO ' . self::getTable() . ' (%s) VALUES (%s)'
			. ' ON DUPLICATE KEY UPDATE %s;';
		$sql = sprintf($sql,
			implode(', ', array_keys($where + $values)),
			implode(', ', $values),
			implode(', ', array_map(
				fn($value) => sprintf('%s=VALUES(%s)', $value, $value)
			, array_keys($values))),
		);
		
		$statement = $this->getSource()->prepare($sql);
		$this->bindValues($statement, $conditions);
		$this->bindValues($statement, $object);
		
		return $statement;
	}
	*/
	
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
	
	public function executeFind(
		array $where = [],
		string $select = '*',
		array $options = [],
		?string $alias = null,
		array $orWhere = [],
		array $whereIn = [],
		array $whereNotIn = [],
		array $isNull = [],
		array $isNotNull = [],
		array $like = [],
		array $notLike = [],
		mixed $limit = null,
		mixed $offset = null,
		array $groupBy = [],
		array $having = [],
		array $orderBy = [],
		array $leftJoin = [],
		array $innerJoin = [],
	): false|PDOStatement
	{
		if($options)
		{
			if(isset($options['order'])) // bc
			{
				$options['orderBy'] = $options['order'];
				unset($options['order']);
			}
			if(isset($options['group'])) // bc
			{
				$options['groupBy'] = $options['group'];
				unset($options['group']);
			}
			
			extract($options, EXTR_IF_EXISTS);
		}
		
		$values = array_values($where) ;
		
		$query = $this->query()
			->select($select);
		
		if($alias !== null)
		{
			$query->alias($alias);
		}
		
		foreach($where as $property => $value)
		{
			$query->andWhere($property . ' = ?');
		}
		
		$orConditions = [];
		foreach($orWhere as $property => $value)
		{
			$orConditions[] = $property . ' = ?';
			$values[] = $value;
		}
		if($orConditions)
		{
			$query->andWhere('(' . implode(' OR ', $orConditions) . ')');
		}
		
		foreach($whereIn as $property => $whereValues)
		{
			$query->andWhereIn($property, $whereValues);
		}
		foreach($whereNotIn as $property => $whereValues)
		{
			$query->andWhereNotIn($property, $whereValues);
		}
		
		foreach($like as $property => $value)
		{
			$query->andWhere($property . ' LIKE ?');
			$values[] = $value;
		}
		foreach($notLike as $property => $value)
		{
			$query->andWhere($property . ' NOT LIKE ?');
			$values[] = $value;
		}
		
		foreach($isNull as $property)
		{
			$query->andWhere($property . ' IS NULL');
		}
		foreach($isNotNull as $property)
		{
			$query->andWhere($property . ' IS NOT NULL');
		}
		
		if($limit !== null)
		{
			$query->limit($limit);
		}
		
		if($offset !== null)
		{
			$query->offset($offset);
		}
		
		if($groupBy)
		{
			$query->groupBy(...$groupBy);
		}
		
		if($having)
		{
			$query->having(...$having);
		}
		
		if($orderBy)
		{
			$query->orderBy(...$orderBy);
		}
		
		if($leftJoin)
		{
			$query->leftJoin(...$leftJoin);
		}
		
		if($innerJoin)
		{
			$query->innerJoin(...$innerJoin);
		}
		
		$query = $this->prepareQuery($query);
		$query->execute($values);
		
		return $query;
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
		$ids = array_unique(array_column($referenced, $referencedBy));
		if(empty($ids))
		{
			return [];
		}
		
		$query = $this->query()
			->select($groupBy . ', ' . static::TABLE . '.*')
			->where(sprintf($groupBy . ' IN (%s)', implode( ', ', $ids)));
		if($queryCallback)
		{
			$queryCallback($query);
		}
		
		$query = $this->getSource()->query($query->getSql());
		return $this->fetchGrouped($query, $class);
	}
	
	public function fetchByReference(
		array $referenced,
		string $referencedBy,
		string $class,
		?Closure $queryCallback = null,
	): array
	{
		$ids = array_keys($referenced);
		if(empty($ids))
		{
			return [];
		}
		
		$query = $this->query()
			->select(static::TABLE . '.*')
			->where(sprintf($referencedBy . ' IN (%s)', implode( ', ', $ids)));
		if($queryCallback)
		{
			$queryCallback($query);
		}
		
		$query = $this->getSource()->query($query->getSql());
		
		return $query->fetchAll(PDO::FETCH_CLASS, $class);
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
	 * Removed all characters that can break AGAINST (... IN BOOLEAN MODE) queries
	 */
	public function sanitizeForBooleanQuery(
		string $query,
	): string
	{
		return preg_replace('~[^\w ]~u', '', $query);
	}
}
