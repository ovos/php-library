<?php
declare(strict_types=1);

namespace Ovos\Store;

use Ovos\Exception;
use Ovos\Model\Mysql as Model;
use Ovos\Store;
use Ovos\Pdo\Expression;
use PDO;
use PDOStatement;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Query\QueryBuilder;
use Closure;
use function Ovos\services;

/**
 * Mysql
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
abstract class Mysql extends Store
{
	/**
	 * Primary table name
	 *
	 * @var string
	 */
	public const TABLE = null;

	/**
	 * @var string
	 */
	protected string $_sourceName = 'database';

	/**
	 * A connection between PHP and a database server
	 *
	 * @var ?PDO
	 */
	protected ?PDO $_source = null;

	/**
	 * @return PDO
	 */
	public function getSource(): PDO
	{
		if($this->_source === null)
		{
			// get database connection
			$this->_source = services()->database->get($this->_sourceName);
		}

		return $this->_source;
	}

	/**
	 * Short for getSource
	 *
	 * @return PDO
	 */
	public function source(): PDO
	{
		return $this->getSource();
	}
	
	/**
	 * @return string
	 */
	public static function getTable(): string
	{
		if(static::TABLE === null)
		{
			throw new Exception('Store is required to have a non-null table name.');
		}

		return static::TABLE;
	}

	/**
	 * Only used for getSQL() calls, never used to query the database
	 * or fetch results due to lack of FETCH_CLASS implementation
	 * 
	 * @return QueryBuilder
	 */
	public function query(): QueryBuilder
	{
		return services()->database->getQueryBuilder($this->_sourceName);
	}

	/**
	 * @param QueryBuilder $query
	 *
	 * @return false|PDOStatement
	 */
	public function prepareQuery(QueryBuilder $query): false|PDOStatement
	{
		return $this->getSource()->prepare($query->getSQL());
	}
	
	/**
	 * @param QueryBuilder $query
	 *
	 * @return false|int
	 */
	public function executeQuery(QueryBuilder $query): false|int
	{
		return $this->getSource()->exec($query->getSQL());
	}
	
	/**
	 * @param QueryBuilder $query
	 *
	 * @return false|PDOStatement
	 */
	public function runQuery(QueryBuilder $query): false|PDOStatement
	{
		return $this->getSource()->query($query->getSQL());
	}

	/**
	 * @return bool
	 */
	public function tableExists(): bool
	{
		return $this->getSource()->query('
			SHOW TABLES LIKE "' . self::getTable() . '"
		')->fetch(PDO::FETCH_NUM) !== false;
	}

	/**
	 * @return bool
	 */
	public function optimize(): bool
	{
		return $this->getSource()->query('
			OPTIMIZE
			TABLE ' . self::getTable() . '
		')->closeCursor();
	}

	/**
	 * @param Model $object
	 *
	 * @return false|PDOStatement
	 */
	public function insertQuery(Model $object): false|PDOStatement
	{
		$values = $this->getQueryValues($object);

		$sql = 'INSERT INTO ' . self::getTable() . ' (%s) VALUES (%s);';
		$sql = sprintf($sql, implode(', ', array_keys($values)), implode(', ', $values));
		
		$statement = $this->getSource()->prepare($sql);
		$this->bindValues($statement, $object);
		
		return $statement;
	}

	/**
	 * @deprecated
	 * @param Model $object
	 * @param array $conditions
	 *
	 * @return false|PDOStatement
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

	/**
	 * @param Model $model
	 * @param Model $updateObject
	 *
	 * @return false|PDOStatement
	 */
	public function updateQuery(Model $model, Model $updateObject): false|PDOStatement
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

	/**
	 * @param Model|array $fields
	 * @param false $sets
	 *
	 * @return array
	 */
	public function getQueryValues(Model|array $fields, $sets = false): array
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

	/**
	 * @param PDOStatement $query
	 * @param Model|array $fields
	 */
	public function bindValues(PDOStatement $query, Model|array $fields): void
	{
		foreach($fields as $field => $value)
		{
			if($value instanceof Expression)
			{
				continue;
			}
			
			$bindType = PDO::PARAM_STR;
			$bindType = is_bool($value) ? PDO::PARAM_BOOL : $bindType;
			$bindType = is_integer($value) ? PDO::PARAM_INT : $bindType;
			
			$query->bindValue(':' . $field, $value, $bindType);
		}
	}

	/**
	 * @param Model $object
	 * @param Model $objectUpdate
	 *
	 * @return bool
	 */
	public function update(Model $object, Model $objectUpdate): bool
	{
		return $object->update($objectUpdate);
	}

	/**
	 * @param Model $model
	 *
	 * @return bool
	 */
	public function insert(Model $model): bool
	{
		return $model->insert();
	}
	
	/**
	 * @param array $where
	 * @param string $select
	 * @param array $options
	 *
	 * @return false|PDOStatement
	 */
	public function executeFind(
		array $where = [],
		string $select = '*',
		array $options = [],
	): false|PDOStatement
	{
		$query = $this->query()
			->select($select)
			->from(static::TABLE);
		
		foreach($where as $property => $value)
		{
			$query->andWhere($property . ' = ?');
		}
		
		if(isset($options['order']))
		{
			$query->orderBy(...$options['order']);
		}
		
		$query = $this->prepareQuery($query);
		$query->execute(array_values($where));
		
		return $query;
	}
	
	/**
	 * @param PDOStatement $statement
	 * @param string $class
	 *
	 * @return array
	 */
	public function fetchGrouped(PDOStatement $statement, string $class): array
	{
		$result = $statement->fetchAll(PDO::FETCH_CLASS | PDO::FETCH_GROUP, $class); // group by first column
		return array_map(fn($row) => reset($row), $result);
	}
	
	/**
	 * @param array $referenced
	 * @param string $referencedBy
	 * @param string $class
	 * @param ?Closure $queryCallback
	 * @param string $groupBy
	 *
	 * @return array
	 */
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
			->from(static::TABLE)
			->where(sprintf($groupBy . ' IN (%s)', implode( ', ', $ids)));
		if($queryCallback)	
		{
			$queryCallback($query);
		}
			
		$query = $this->getSource()->query($query->getSQL());
		return $this->fetchGrouped($query, $class);
	}
	
	/**
	 * @param array $referenced
	 * @param string $referencedBy
	 * @param string $class
	 * @param ?Closure $queryCallback
	 *
	 * @return array
	 */
	public function fetchByReference(
		array $referenced,
		string $referencedBy,
		string $class,
		?Closure $queryCallback = null
	): array
	{
		$ids = array_keys($referenced);
		if(empty($ids))
		{
			return [];
		}
		
		$query = $this->query()
			->select(static::TABLE . '.*')
			->from(static::TABLE)
			->where(sprintf($referencedBy . ' IN (%s)', implode( ', ', $ids)));
		if($queryCallback)	
		{
			$queryCallback($query);
		}
		
		$query = $this->getSource()->query($query->getSQL());
		return $query->fetchAll(PDO::FETCH_CLASS, $class);
	}
	
	/**
	 * @param array $referenced
	 * @param string $referencedBy
	 * @param string $reference
	 * @param array $items
	 * @param string $key
	 *
	 * @return array
	 */
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
			
			/*
			$array = $model->getReference($reference);
			$array[$item->$key] = $item;
			
			$model->setReference($reference, $array);
			*/
		}
		
		return $referenced;
	}
	
	/**
	 * @param array $referenced
	 * @param string $referencedBy
	 * @param string $reference
	 * @param array $items
	 *
	 * @return array
	 */
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
}
