<?php
declare(strict_types=1);

namespace Ovos\Model;

use Ovos\Exception;
use Ovos\Model;
use Ovos\Store\Mysql as Store;
use Ovos\Model\Mysql\Template;
use Ovos\Pdo\Expression;
use stdClass;
use Iterator;
use Countable;
use PDO;
use PDOStatement;
use ReflectionClass;
use ReflectionObject;
use ReflectionProperty;
use function Ovos\services;

/**
 * Mysql
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 *
 * @property string $created_at
 * @property string $modified_at
 */
abstract class Mysql extends Model implements Iterator, Countable
{
	/**
	 * @var string
	 */
	protected $_sourceName = 'database';

	/**
	 * A connection between PHP and a database server
	 *
	 * @var PDO
	 */
	protected $_source;

	/**
	 * List of primary keys
	 *
	 * @var array
	 */
	protected $_primaryKeys = ['id'];

	/**
	 * Autoincrement primary key
	 *
	 * @var null|string
	 */
	protected $_autoIncrementKey = 'id';

	/**
	 * @var array
	 */
	protected $_templates = [];

	/**
	 * @var array
	 */
	protected $_setters = [];

	/**
	 * @var array
	 */
	protected $_getters = [];

	/**
	 * @var array
	 */
	protected $_properties = [];

	/**
	 * @var array
	 */
	protected $_modified = [];

	/**
	 * @var self
	 */
	protected $_updateObject;

	/**
	 */
	public function __construct()
	{
		parent::__construct();
		
		// reset modified values (after initializing the object by PDO)
		$this->resetModified();
		
		// set up whatever needs to be set
		$this->triggerEvents('setUp');
	}

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
	public function _initSource()
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
	 * @return array
	 */
	public function getPrimaryKeys(): array
	{
		return $this->_primaryKeys;
	}

	/**
	 * @return null|string
	 */
	public function getAutoIncrementKey(): ?string
	{
		return $this->_autoIncrementKey;
	}

	/**
	 * @param array $properties
	 *
	 * @return $this
	 */
	public function setProperties(array $properties): self
	{
		$this->_properties = $properties;

		return $this;
	}

	/**
	 * @return array
	 */
	public function getProperties(): array
	{
		return $this->_properties;
	}

	/**
	 * @param string $name
	 * @param mixed $value
	 *
	 * @return $this
	 */
	public function setProperty(string $name, $value): self
	{
		if(
			// property does not exist
			array_key_exists($name, $this->_properties) === false
			// property exists and the value was modified 
			|| (
				array_key_exists($name, $this->_properties)
				&& array_key_exists($name, $this->_modified) === false
				&& $this->_properties[$name] !== $value)
			)
		{
			$this->_modified[$name] = $value;
		}

		$this->_properties[$name] = $value;

		return $this;
	}

	/**
	 * @param string $name
	 *
	 * @return array
	 */
	public function getProperty($name): array
	{
		if(!isset($this->_properties[$name]))
		{
			return null;
		}

		return $this->_properties[$name];
	}

	/**
	 * @param array $modified
	 *
	 * @return $this
	 */
	public function setModified(array $modified): self
	{
		$this->_modified = $modified;

		return $this;
	}

	/**
	 * @return array
	 */
	public function getModified(): array
	{
		return $this->_modified;
	}

	/**
	 * @return bool
	 */
	public function isModified(): bool
	{
		return \count($this->_modified) > 0;
	}
	
	/**
	 * @return $this
	 */
	public function resetModified(): self
	{
		$this->_modified = [];
	
		return $this;
	}

	/**
	 * @param null|self $updateObject
	 *
	 * @return $this
	 */
	public function setUpdateObject(?self $updateObject): self
	{
		$this->_updateObject = $updateObject;

		return $this;
	}

	/**
	 * @return null|self
	 */
	public function getUpdateObject(): ?self
	{
		return $this->_updateObject;
	}

	/**
	 * @param Template $template
	 *
	 * @return $this
	 */
	public function addTemplate(Template $template): self
	{
		$this->_templates[] = $template;

		return $this;
	}

	/**
	 * @return array
	 */
	public function getTemplates(): array
	{
		return $this->_templates;
	}

	/**
	 * @param string $property
	 * @param string $method
	 *
	 * @return $this
	 */
	public function addSetter(string $property, string $method): self
	{
		$this->_setters[$property] = $method;

		return $this;
	}

	/**
	 * @param string $property
	 *
	 * @return $this
	 */
	public function removeSetter(string $property): self
	{
		unset($this->_setters[$property]);

		return $this;
	}

	/**
	 * @param string $property
	 * @param string $method
	 *
	 * @return $this
	 */
	public function addGetter(string $property, string $method): self
	{
		$this->_getters[$property] = $method;

		return $this;
	}

	/**
	 * @param string $property
	 *
	 * @return $this
	 */
	public function removeGetter(string $property): self
	{
		unset($this->_getters[$property]);

		return $this;
	}
	
	/**
	 * @param string $property
	 * @param string $getter
	 * @param string $setter
	 *
	 * @return $this
	 */
	public function addManipulators(string $property,
		string $getter, string $setter): self
	{
		$this->addGetter($property, $getter);
		$this->addSetter($property, $setter);

		return $this;
	}


	/**
	 * @param string $property
	 *
	 * @return mixed
	 */
	public function __get(string $property)
	{
		$value = $this->__getRaw($property);
		if($value === null)
		{
			return null;
		}

		// run getter
		if(isset($this->_getters[$property]))
		{
			$method = &$this->_getters[$property];
			// check if getter method is part of a template
			foreach($this->getTemplates() as $template)
			{
				if(method_exists($template, $method))
				{
					$value = $template->{$method}($this, $value);
				}
			}		
			
			// or is it an own method
			if(method_exists($this, $method))
			{
				$value = $this->{$method}($value);
			}
		}

		return $value;
	}

	/**
	 * @param string $property
	 *
	 * @return mixed
	 */
	public function __getRaw(string $property)
	{
		if($this->__isset($property) === false)
		{
			return null;
		}

		return $this->_properties[$property];
	}

	/**
	 * @param string $property
	 *
	 * @return bool
	 */
	public function __isset(string $property): bool
	{
		return array_key_exists($property, $this->_properties);
	}

	/**
	 * @param string $property
	 * @param mixed $value
	 *
	 * @return $this
	 */
	public function __set(string $property, $value): self
	{
		// run setter
		if(isset($this->_setters[$property]))
		{
			$method = &$this->_setters[$property];
			// check if setter method is part of a template
			foreach($this->getTemplates() as $template)
			{
				if(method_exists($template, $method))
				{
					$value = $template->{$method}($this, $value);
				}
			}		
			
			// or is it an own method
			if(method_exists($this, $method))
			{
				$value = $this->{$method}($value);
			}
		}

		$this->__setRaw($property, $value);

		return $this;
	}

	/**
	 * @param string $property
	 * @param mixed $value
	 *
	 * @return $this
	 */
	public function __setRaw(string $property, $value): self
	{
		$this->setProperty($property, $value);

		// set also on update object
		if($this->_updateObject !== null)
		{
			$this->_updateObject->$property = $value;
		}

		return $this;
	}

	/**
	 * @param string $property
	 *
	 * @return $this
	 */
	public function __unset(string $property)
	{
		unset($this->_properties[$property]);

		// set also on update object
		if($this->_updateObject !== null)
		{
			unset($this->_updateObject->$property);
		}

		return $this;
	}

	/**
	 * @param object $source
	 * @param array $skip fields to skip
	 *
	 * @return self
	 */
	public static function import(object $source, array $skip = []): self
	{
		/** @var self $destination */
		$destination = new static; // late static binding
		foreach($source as $property => $value)
		{
			if(\in_array($property, $skip, true) === true)
			{
				continue;
			}
			$destination->$property = $value;
		}

		return $destination;
	}
	
	/**
	 * @param array $values
	 */
	public function fromArray(array $values)
	{
		foreach($values as $property => $value)
		{
			$this->$property = $value;
		}
	}

	/**
	 * @param array $skip fields to skip
	 *
	 * @return stdClass
	 */
	public function export(array $skip = []): stdClass
	{
		$destination = new stdClass;

		foreach($this as $property => $value)
		{
			if(\in_array($property, $skip, true) === true)
			{
				continue;
			}
			$destination->$property = $this->__get($property);
		}

		return $destination;
	}

	/**
	 * @return array
	 */
	public function __debugInfo()
	{
		return $this->toArray();
	}

	/**
	 * @return int
	 */
	public function count(): int
	{
		return \count($this->_properties);
	}

	/**
	 * @return array
	 */
	public function toArray(): array
	{
		return (array)$this->export();
	}

	/**
	 * @return void
	 */
	public function rewind(): void
	{
		reset($this->_properties);
	}

	/**
	 * @return mixed
	 */
	public function current()
	{
		return current($this->_properties);
	}

	/**
	 * @return void
	 */
	public function next(): void
	{
		next($this->_properties);
	}

	/**
	 * @return int|mixed|null|string
	 */
	public function key()
	{
		return key($this->_properties);
	}

	/**
	 * @return bool
	 */
	public function valid(): bool
	{
		$key = $this->key();

		return ($key !== null && $key !== false);
	}

	/**
	 * @return bool
	 *
	 * @throws Exception
	 */
	public function insert(): bool
	{
		$storeClass = static::getStoreClass();
		/** @var Store $store */
		$store = new $storeClass;

		$this->triggerEvents('preInsert', 'preSave');
		$query = $store->insertQuery($this);
		$store->bindValues($query, $this);

		$result = $query->execute();
		if($this->_autoIncrementKey)
		{
			$this->{$this->_autoIncrementKey} = $this->source()->lastInsertId();
		}
		
		// reset modified values
		$this->resetModified();

		return $result;
	}

	/**
	 * @param string $property
	 * @param callable $generator
	 * @param int $attemptsMax
	 *
	 * @return bool
	 */
	public function insertUnique(string $property, callable $generator, int $attemptsMax): bool
	{
		$attempt = 1;
		$lastValue = null;
		while(true)
		{
			try
			{
				$lastValue = $generator($attempt, $lastValue);
				$this->__set($property, $lastValue);
				return $this->insert();
			}
			catch(\Exception $exception)
			{
				if($attempt >= $attemptsMax)
				{
					return false;
				}
				$attempt++;
			}
		}

		return false;
	}

	/**
	 * @param self $updateObject
	 *
	 * @return bool
	 *
	 * @throws Exception
	 */
	public function update(self $updateObject): bool
	{
		$conditions = [];
		foreach($this->_primaryKeys as $primaryKey)
		{
			if(empty($this->{$primaryKey}))
			{
				throw new Exception('Primary key "%s" cannot be empty.', $primaryKey);
			}

			$conditions[$primaryKey] = $this->{$primaryKey};
		}

		$storeClass = static::getStoreClass();
		/** @var Store $store */
		$store = new $storeClass;
		$this->setUpdateObject($updateObject);
		$this->triggerEvents('preUpdate', 'preSave');
		$query = $store->updateQuery($updateObject, $conditions);
		$store->bindValues($query, $updateObject);
		$result = $query->execute();

		// update current object on success
		if($result)
		{
			foreach($updateObject as $property => $value)
			{
				$this->__set($property, $value);
			}
			
			// reset modified values
			$this->resetModified();
		}

		return $result;
	}

	/**
	 * @return bool
	 */
	public function save(): bool
	{
		if($this->isModified() === false)
		{
			return false;
		}

		$updateObject = self::import($this->getModifiedValues());
		return $this->update($updateObject);
	}

	/**
	 * @return bool
	 *
	 * @throws Exception
	 */
	public function refresh(): bool
	{
		$properties = array_keys($this->_properties);

		// refresh only loaded fields
		$query = $this->source()->prepare('
			SELECT ' . implode(', ', $properties) . '
			FROM ' . self::getTable() . '
			WHERE ' . $this->_getPrimaryKeysCondition()
		);
		$this->_bindPrimaryKeys($query);
		$result = $query->execute();

		if($result)
		{
			$updatedModel = $query->fetchObject();
			foreach($updatedModel as $property => $value)
			{
				$this->__setRaw($property, $value);
			}

			// reset modified values
			$this->resetModified();
		}

		return $result;
	}

	/**
	 * @return bool
	 *
	 * @throws Exception
	 */
	public function delete(): bool
	{
		$this->triggerEvents('preDelete');
		// refresh only loaded fields
		$query = $this->source()->prepare('
			DELETE FROM ' . self::getTable() . '
			WHERE ' . $this->_getPrimaryKeysCondition()
		);
		$this->_bindPrimaryKeys($query);

		return $query->execute();
	}

	/**
	 * @return string
	 */
	public static function getTable(): string
	{
		$storeClass = static::getStoreClass();
		/** @var Store $storeClass */
		return $storeClass::getTable();
	}

	/**
	 * @return string
	 */
	protected function _getPrimaryKeysCondition(): string
	{
		$conditions = array();
		foreach($this->_primaryKeys as $primaryKey)
		{
			$conditions[] = sprintf('%s = :%s', $primaryKey, $primaryKey);
		}

		return implode(' AND ', $conditions);
	}

	/**
	 * @param PDOStatement $query
	 *
	 * @return void
	 *
	 * @throws Exception
	 */
	protected function _bindPrimaryKeys(PDOStatement $query): void
	{
		foreach($this->_primaryKeys as $primaryKey)
		{
			if(empty($this->_properties[$primaryKey]))
			{
				throw new Exception('Primary key "%s" cannot be empty.', $primaryKey);
			}

			$query->bindParam(':' . $primaryKey, $this->_properties[$primaryKey],
				PDO::PARAM_STR);
		}
	}

	/**
	 * @param array|null $filter
	 *
	 * @return stdClass
	 */
	public function getValues(?array $filter = null): stdClass
	{
		$values = new stdClass;

		foreach($this as $property => $value)
		{
			if($filter === null || \in_array($property, $filter, true) === true)
			{
				$values->{$property} = $this->__get($property);
			}
		}

		return $values;
	}

	/**
	 * @return stdClass
	 */
	public function getModifiedValues(): stdClass
	{
		$values = new stdClass;

		foreach($this as $property => $value)
		{
			if(array_key_exists($property, $this->_modified) === false)
			{
				continue;
			}

			$values->{$property} = $this->__get($property);
		}

		return $values;
	}

	/**
	 * @param mixed ...$events
	 */
	public function triggerEvents(...$events)
	{
		foreach($events as $event)
		{
			$this->$event();
		
			foreach($this->getTemplates() as $template)
			{
				$template->$event($this);
			}
		}
	}

	/**
	 * @return null|string
	 */
	public function getErrorMessage(): ?string
	{
		return $this->getSource()->errorInfo()[2]; // element [2] is null when there is no error
	}

	/**
	 */
	public function setUp(): void
	{
	}

	/**
	 */
	public function preInsert(): void
	{
	}

	/**
	 */
	public function preUpdate(): void
	{
	}

	/**
	 */
	public function preSave(): void
	{
	}

	/**
	 */
	public function preDelete(): void
	{
	}
}
