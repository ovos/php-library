<?php
declare(strict_types=1);

namespace Ovos\Store;

use Ovos\Exception;
use Ovos\Model\Mysql as Model;
use Ovos\Store;
use Ovos\Pdo\Expression;
use PDO;
use PDOStatement;
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
			$this->_initSource();
		}

		return $this->_source;
	}

	/**
	 */
	public function _initSource(): void
	{
		// get database connection
		$this->_source = services()->database->get($this->_sourceName);
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
		$names = $values = [];
		foreach($object as $name => $value)
		{
			$names[] = $name;
			if($value instanceof Expression)
			{
				$values[] = $value->__toString();
			}
			else
			{
				$values[] = ':' . $name;
			}
		}

		$sql = 'INSERT INTO ' . self::getTable() . ' (%s) VALUES (%s);';
		$sql = sprintf($sql, implode(', ', $names), implode(', ', $values));

		return $this->source()->prepare($sql);
	}


	/**
	 * @param Model $object
	 * @param array $conditions
	 * @param string|null $table
	 *
	 * @return false|PDOStatement
	 *
	 * @throws Exception
	 */
	public function updateQuery(Model $object, array $conditions = [], ?string $table = null)
	{
		if(empty($conditions))
		{
			throw new Exception('At least one update condition is required.');
		}

		$sets = [];
		foreach($object as $name => $value)
		{
			if($value instanceof Expression)
			{
				$sets[] = $name . ' = ' . $value->__toString();
			}
			else
			{
				$sets[] = $name . ' = :' . $name;
			}
		}

		$where = [];
		foreach($conditions as $field => $value)
		{
			$where[] = $field . ' = :' . $field;
		}

		$sql = 'UPDATE ' . self::getTable() . ' SET %s WHERE ' . implode(' AND ', $where);
		$sql = sprintf($sql, implode(', ', $sets));

		$query = $this->source()->prepare($sql);
		foreach($conditions as $field => $value)
		{
			$query->bindParam(':' . $field, $value, PDO::PARAM_STR);
		}

		return $query;
	}

	/**
	 * @param PDOStatement $query
	 * @param Model $object
	 */
	public function bindValues(PDOStatement $query, Model $object): void
	{
		foreach($object as $name => $value)
		{
			if($value instanceof Expression)
			{
				continue;
			}

			if(is_numeric($value))
			{
				$query->bindValue(':' . $name, $value, PDO::PARAM_INT);
			}
			else
			{
				$query->bindValue(':' . $name, $value, PDO::PARAM_STR);
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
}
