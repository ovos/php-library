<?php
declare(strict_types=1);

namespace Ovos\Model;

use Ovos\ArrayObject;
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
use function in_array;
use function count;
use function array_key_exists;
use function method_exists;
use function reset;
use function current;
use function next;
use function key;

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
	/**#@+
	 * Export constants
	 */
	public const EXPORT_TYPE_STDCLASS = 0;
	public const EXPORT_TYPE_ARRAY = 1;
	public const EXPORT_TYPE_ARRAYOBJECT = 2;
	/**#@-*/

	/**
	 * Return null by reference
	 */
	public mixed $null = null;

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
	 * List of primary keys
	 *
	 * @var array
	 */
	protected array $_primaryKeys = ['id'];

	/**
	 * Autoincrement key
	 *
	 * @var ?string
	 */
	protected ?string $_autoIncrementKey = 'id';

	/**
	 * @var array
	 */
	protected array $_templates = [];

	/**
	 * @var array
	 */
	protected array $_setters = [];

	/**
	 * @var array
	 */
	protected array $_getters = [];

	/**
	 * @var array
	 */
	protected array $_properties = [];

	/**
	 * @var array
	 */
	protected array $_modified = [];
	
	/**
	 * @var array
	 */
	protected array $_references = [];	
	
	/**
	 * A record exists if it was fetched with PK, otherwise it's considered new
	 * 
	 * @var bool
	 */
	protected bool $_exists = false;

	/**
	 * @var ?self
	 */
	protected ?self $_updateObject = null;

	/**
	 * @param array|null $properties
	 */
	public function __construct(?array $properties = null)
	{
		parent::__construct();
		
		$this->_exists = $this->_primaryKeysLoaded();
		
		if($properties !== null)
		{
			$this->fromArray($properties);
		}
		
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
	 * @return array
	 */
	public function getPrimaryKeys(): array
	{
		return $this->_primaryKeys;
	}

	/**
	 * @return ?string
	 */
	public function getAutoIncrementKey(): ?string
	{
		return $this->_autoIncrementKey;
	}

	/**
	 * @param array $properties
	 *
	 * @return self
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
	 * @return self
	 */
	public function setProperty(string $name, mixed $value): self
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
	 * @return mixed
	 */
	public function getProperty($name): mixed
	{
		if(!isset($this->_properties[$name]))
		{
			return null;
		}

		return $this->_properties[$name];
	}

	/**
	 * @param string $name
	 * @param mixed $value
	 *
	 * @return self
	 */
	public function setReference(string $name, mixed $value): self
	{
		$this->_references[$name] = $value;

		return $this;
	}

	/**
	 * @param string $name
	 *
	 * @return mixed
	 */
	public function &getReference(string $name): mixed
	{
		if(isset($this->_references[$name]))
		{
			return $this->_references[$name];
		}

		return $this->null;
	}
	
	/**
	 * @param string $name
	 *
	 * @return bool
	 */
	public function hasReference(string $name): bool
	{
		return isset($this->_references[$name]);
	}
	
	/**
	 * @param string $name
	 * @param mixed $value
	 *
	 * @return self
	 */
	public function reference(string $name, mixed $value = null): self
	{
		if($value === null)
		{
			return $this->getReference($name);
		}
		
		return $this->setReference($name, $value);
	}

	/**
	 * @param array $modified
	 *
	 * @return self
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
		return count($this->_modified) > 0;
	}
	
	/**
	 * @return self
	 */
	public function resetModified(): self
	{
		$this->_modified = [];
	
		return $this;
	}

	/**
	 * @param ?self $updateObject
	 *
	 * @return self
	 */
	public function setUpdateObject(?self $updateObject): self
	{
		$this->_updateObject = $updateObject;

		return $this;
	}

	/**
	 * @return ?self
	 */
	public function getUpdateObject(): ?self
	{
		return $this->_updateObject;
	}

	/**
	 * @param Template $template
	 *
	 * @return self
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
	 * @param bool $prepend
	 *
	 * @return self
	 */
	public function addSetter(string $property, string $method, bool $prepend = false): self
	{
		if(array_key_exists($property, $this->_setters) === false)
		{
			$this->_setters[$property] = [];
		}
	
		if($prepend)
		{
			array_unshift($this->_setters[$property], $method);
		}
		else
		{
			array_push($this->_setters[$property], $method);
		}

		return $this;
	}

	/**
	 * @param string $property
	 *
	 * @return self
	 */
	public function removeSetter(string $property): self
	{
		if(array_key_exists($property, $this->_setters) === false) // no getters for this property
		{
			return $this;
		}
	
		$methodIndex = array_search($this->_setters[$property], $method, true);
		if($methodIndex === false) // method not found
		{
			return $this;
		}
		
		unset($this->_setters[$property][$methodIndex]);

		return $this;
	}

	/**
	 * @param string $property
	 * @param string $method
	 * @param bool $prepend
	 *
	 * @return self
	 */
	public function addGetter(string $property, string $method, bool $prepend = false): self
	{
		if(array_key_exists($property, $this->_getters) === false)
		{
			$this->_getters[$property] = [];
		}
		
		if($prepend)
		{
			array_unshift($this->_getters[$property], $method);
		}
		else
		{
			array_push($this->_getters[$property], $method);
		}

		return $this;
	}

	/**
	 * @param string $property
	 * @param string $method
	 *
	 * @return self
	 */
	public function removeGetter(string $property, string $method): self
	{
		if(array_key_exists($property, $this->_getters) === false) // no getters for this property
		{
			return $this;
		}
	
		$methodIndex = array_search($this->_getters[$property], $method, true);
		if($methodIndex === false) // method not found
		{
			return $this;
		}
		
		unset($this->_getters[$property][$methodIndex]);

		return $this;
	}
	
	/**
	 * @param string $property
	 * @param string $getter
	 * @param string $setter
	 * @param bool $prepend
	 *
	 * @return self
	 */
	public function addManipulators(string $property,
		string $getter, string $setter, bool $prepend = false): self
	{
		$this->addGetter($property, $getter, $prepend);
		$this->addSetter($property, $setter, $prepend);

		return $this;
	}
	
	/**
	 * @param string $property
	 *
	 * @return mixed
	 */
	public function __get(string $property): mixed
	{
		$value = $this->__getRaw($property);
		if($value === null)
		{
			return null;
		}
			
		// run getter
		if(isset($this->_getters[$property]))
		{
			foreach($this->_getters[$property] as $method)
			{
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
		}

		return $value;
	}

	/**
	 * @param string $property
	 *
	 * @return mixed
	 */
	public function __getRaw(string $property): mixed
	{
		if(array_key_exists($property, $this->_references))
		{
			return $this->_references[$property];
		}
	
		if(array_key_exists($property, $this->_properties))
		{
			return $this->_properties[$property];
		}

		return null;
	}

	/**
	 * @param string $property
	 *
	 * @return bool
	 */
	public function __isset(string $property): bool
	{
		return array_key_exists($property, $this->_properties)
			|| array_key_exists($property, $this->_references);
	}

	/**
	 * Called also by PDO on FETCH_CLASS
	 * 
	 * @param string $property
	 * @param mixed $value
	 */
	public function __set(string $property, mixed $value): void
	{
		// run setter
		if(isset($this->_setters[$property]))
		{
			foreach($this->_setters[$property] as $method)
			{
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
		}

		$this->__setRaw($property, $value);
	}

	/**
	 * @param string $property
	 * @param mixed $value
	 */
	public function __setRaw(string $property, mixed $value): void
	{
		$this->setProperty($property, $value);

		// set also on update object
		if($this->_updateObject !== null)
		{
			$this->_updateObject->$property = $value;
		}
	}

	/**
	 * @param string $property
	 */
	public function __unset(string $property): void
	{
		if(array_key_exists($property, $this->_properties))
		{
			unset($this->_properties[$property]);
		}
		
		if(array_key_exists($property, $this->_references))
		{
			unset($this->_references[$property]);
		}

		// set also on update object
		if($this->_updateObject !== null)
		{
			unset($this->_updateObject->$property);
		}
	}

	/**
	 * @param object $source
	 * @param array $skip fields to skip
	 *
	 * @return self
	 */
	public static function import(object $source, array $skip = []): self
	{
		$destination = new static; // late static binding
		foreach($source as $property => $value)
		{
			if(in_array($property, $skip, true) === true)
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
	public function fromArray(array $values): void
	{
		foreach($values as $property => $value)
		{
			$this->$property = $value;
		}
	}

	/**
	 * @param array $skip fields to skip
	 * @param bool $references include references
	 * @param int $type
	 *
	 * @return stdClass|array
	 */
	public function export(array $skip = [],
		bool $references = true,
		int $type = self::EXPORT_TYPE_STDCLASS): stdClass|array|ArrayObject
	{
		$export = new stdClass;

		foreach($this as $property => $value)
		{
			if(in_array($property, $skip, true) === true)
			{
				continue;
			}
			
			$export->$property = $this->__get($property);
		}
		
		if($references)
		{
			$this->_exportReferences($export, $skip, $type);
		}

		return $this->_getExportType($export, $type);
	}
	
	/**
	 * @param stdClass $export
	 * @param array $skip fields to skip
	 * @param int $type
	 *
	 * @return stdClass|array
	 */
	protected function _exportReferences(stdClass $export,
		array $skip = [],
		int $type = self::EXPORT_TYPE_STDCLASS): void
	{
		foreach($this->_references as $reference => $value)
		{
			if(in_array($reference, $skip, true) === true)
			{
				continue;
			}
			
			// passed as key => children
			$referenceSkip = isset($skip[$reference]) ? $skip[$reference] : [];
			
			$exportReferences = $this->__get($reference);
			// relation to many
			if(is_array($exportReferences))
			{
				foreach($exportReferences as &$exportReference)
				{
					/**
					 * @var self $exportReference
					 */
					$exportReference = $exportReference->export($referenceSkip, true, $type);
				}
			}
			// relation to one
			else
			{
				/**
				 * @var self $exportReferences
				 */
				$exportReferences = $exportReferences->export($referenceSkip, true, $type);
			}
			
			$export->$reference = $exportReferences;
		}
	}

	/**
	 * @param stdClass $object
	 * @param int $type
	 *
	 * @return stdClass|array|ArrayObject
	 */
	protected function _getExportType(stdClass $object, int $type):  stdClass|array|ArrayObject
	{
		return match($type)
		{
			self::EXPORT_TYPE_STDCLASS => $object,
			self::EXPORT_TYPE_ARRAY => (array)$object,
			self::EXPORT_TYPE_ARRAYOBJECT => new ArrayObject((array)$object),
		};
	}

	/**
	 * @return array
	 */
	public function __debugInfo(): array
	{
		return $this->export(references: true, type: self::EXPORT_TYPE_ARRAY);
	}

	/**
	 * @return int
	 */
	public function count(): int
	{
		return count($this->_properties);
	}

	/**
	 * @param array $skip fields to skip
	 * @param bool $references include references
	 * 
	 * @return array
	 */
	public function toArray(array $skip = [],
		bool $references = true): array
	{
		return $this->export($skip, $references, self::EXPORT_TYPE_ARRAY);
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
	public function current(): mixed
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
	 * @return null|int|string
	 */
	public function key(): null|int|string
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
	 */
	public function insert(): bool
	{
		$storeClass = static::getStoreClass();
		/** @var Store $store */
		$store = new $storeClass;

		$this->triggerEvents('preInsert', 'preSave');
		$query = $store->insertQuery($this);

		$result = $query->execute();
		if($this->_autoIncrementKey)
		{
			$this->{$this->_autoIncrementKey} = (int)$this->source()->lastInsertId();
		}
		
		// reset modified values
		$this->resetModified();
		$this->_exists = true;

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
	}

	/**
	 * @param self $updateObject
	 *
	 * @return bool
	 */
	public function update(self $updateObject): bool
	{
		$storeClass = static::getStoreClass();
		/** @var Store $store */
		$store = new $storeClass;
		$this->setUpdateObject($updateObject);
		$this->triggerEvents('preUpdate', 'preSave');
		$query = $store->updateQuery($this, $updateObject);
		$result = $query->execute();
		
		// update current object on success
		if($result)
		{
			foreach($updateObject as $property => $value)
			{
				$this->__set($property, $updateObject->__get($property));
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
		if($this->exists() === false)
		{
			return $this->insert();
		}
	
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
			WHERE ' . $this->getPrimaryKeysConditions()
		);
		$this->bindPrimaryKeys($query);
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
			WHERE ' . $this->getPrimaryKeysConditions()
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
	 * @return bool
	 */
	public function exists(): bool
	{
		return $this->_exists;
	}

	/**
	 * Can be called only after population by PDO::FETCH_CLASS
	 * @see https://electrictoolbox.com/php-pdo-fetch-class-gotcha/
	 * 
	 * @return bool
	 */
	protected function _primaryKeysLoaded(): bool
	{
		foreach($this->_primaryKeys as $primaryKey)
		{
			if(empty($this->_properties[$primaryKey]))
			{
				return false;
			}
		}
		
		return true;
	}
	
	/**
	 * @return string
	 */
	public function getPrimaryKeysConditions(): string
	{
		if($this->exists() === false)
		{
			throw new Exception('Primary keys have to be selected for update.');
		}
	
		$conditions = [];
		foreach($this->_primaryKeys as $primaryKey)
		{
			$conditions[] = sprintf('%s = :%s', $primaryKey, $primaryKey);
		}

		return implode(' AND ', $conditions);
	}

	/**
	 * @param PDOStatement $statement
	 *
	 * @return void
	 */
	public function bindPrimaryKeys(PDOStatement $statement): void
	{
		foreach($this->_primaryKeys as $primaryKey)
		{
			$statement->bindParam(':' . $primaryKey, $this->_properties[$primaryKey],
				PDO::PARAM_STR);
		}
	}

	/**
	 * @param ?array $filter
	 *
	 * @return stdClass
	 */
	public function getValues(?array $filter = null): stdClass
	{
		$values = new stdClass;

		foreach($this as $property => $value)
		{
			if($filter === null || in_array($property, $filter, true) === true)
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
	public function triggerEvents(...$events): void
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
	 * @return ?string
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
