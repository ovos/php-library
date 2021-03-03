<?php
declare(strict_types=1);

namespace Ovos\Store;

use Ovos\Exception;
use Ovos\Model\Mysql as Model;
use Ovos\Store;
use Ovos\Pdo\Expression;
use PDO;
use PDOStatement;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Query\QueryBuilder;
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
	 * @var null|PDO
	 */
	protected null|PDO $_source = null;

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
	 * @return QueryBuilder
	 * @throws \Doctrine\DBAL\Exception
	 */
	public function query(): QueryBuilder
	{
		static $connection = null;
		
		if($connection === null)
		{
			$connection = DriverManager::getConnection([
				'driver' => 'pdo_mysql',
				'pdo' => $this->getSource(),
			]);
		}
		
		return $connection->createQueryBuilder();
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
	public function optimize()
	{
		return $this->source()->query('
			OPTIMIZE
			TABLE ' . self::getTable() . '
		')->closeCursor();
	}

	/**
	 * @param Model $object
	 *
	 * @return false|PDOStatement
	 *
	 * @throws Exception
	 */
	public function insertQuery(Model $object)
	{
		$values = $this->getQueryValues($object);

		$sql = 'INSERT INTO ' . self::getTable() . ' (%s) VALUES (%s);';
		$sql = sprintf($sql, implode(', ', array_keys($values)), implode(', ', $values));

		$statement = $this->source()->prepare($sql);
		$this->bindValues($statement, $object);
		
		return $statement;
	}

	/**
	 * @deprecated
	 * @param Model $object
	 * @param array $conditions
	 *
	 * @return false|PDOStatement
	 *
	 * @throws Exception
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

		$statement = $this->source()->prepare($sql);
		$this->bindValues($statement, $conditions);
		$this->bindValues($statement, $object);
		
		return $statement;
	}
	*/

	/**
	 * @param Model $object
	 *
	 * @return false|PDOStatement
	 *
	 * @throws Exception
	 */
	public function updateQuery(Model $model, Model $updateObject)
	{
		$sets = $this->getQueryValues($updateObject, true);

		$sql = 'UPDATE ' . self::getTable() . ' SET %s'
			. ' WHERE ' . $model->getPrimaryKeysConditions();
		$sql = sprintf($sql, implode(', ', $sets));

		$statement = $this->source()->prepare($sql);
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

			if(is_numeric($value))
			{
				$query->bindValue(':' . $field, $value, PDO::PARAM_INT);
			}
			else
			{
				$query->bindValue(':' . $field, $value, PDO::PARAM_STR);
			}
		}
	}

	/**
	 * @param Model $object
	 * @param Model $objectUpdate
	 *
	 * @return bool
	 *
	 * @throws Exception
	 */
	public function update($object, $objectUpdate): bool
	{
		return $object->update($objectUpdate);
	}

	/**
	 * @param Model $model
	 *
	 * @return bool
	 *
	 * @throws Exception
	 */
	public function insert($model): bool
	{
		return $model->insert();
	}
	
	/**
	 * @param PDOStatement $statement
	 * @param string $class
	 *
	 * @return array
	 */
	public function fetchGrouped(PDOStatement $statement, string $class): array
	{
		$result = $statement->fetchAll(PDO::FETCH_CLASS | PDO::FETCH_GROUP , $class); // group by first column
		return array_map(fn($row) => reset($row), $result);
	}
}
