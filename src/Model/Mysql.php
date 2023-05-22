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
	protected array $_gettersCache = [];

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
	 * Should be used when initializing multiple properties at once.
	 * The change will not trigger setters
	 * and will not be recorded as modification.
	 * Used for restoring model's state.
	 * 
	 * @param array $properties
	 *
	 * @return self
	 */
	public function setProperties(array $properties): self
	{
		foreach($properties as $property => $value)
		{
			$this->setProperty($property, $value);
		}

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
	 * Should be used to initialize a single property.
	 * The change will not trigger setters
	 * and will not be recorded as modification.
	 * Used for restoring model's state.
	 * 
	 * @param string $property
	 * @param mixed $value
	 *
	 * @return self
	 */
	public function setProperty(string $property, mixed $value): self
	{
		$this->_properties[$property] = $value;

		return $this;
	}
	
	/**
	 * @param string $property
	 *
	 * @return mixed
	 */
	public function getProperty($property): mixed
	{
		if(array_key_exists($property, $this->_properties) === false)
		{
			return null;
		}

		return $this->_properties[$property];
	}
	
	/**
	 * Should be used to modify a single property, while skipping the setters
	 * The change will be recorded as modification
	 * 
	 * @param string $property
	 * @param mixed $value
	 *
	 * @return self
	 */
	public function modifyProperty(string $property, mixed $value): self
	{
		if(
			// property does not exist
			array_key_exists($property, $this->_properties) === false
			// property exists and the value was modified 
			|| (
				array_key_exists($property, $this->_properties)
				&& array_key_exists($property, $this->_modified) === false
				&& $this->_properties[$property] !== $value)
			)
		{
			$this->_modified[$property] = $value;
		}
		
		$this->setProperty($property, $value);
		
		// record the change on update object
		if($this->_updateObject !== null)
		{
			$this->_updateObject->modifyProperty($property, $value);
		}
		
		return $this;
	}
	
	/**
	 * Should be used to modify multiple properties at once, while skipping the setters
	 * The change will be recorded as modification.
	 * 
	 * @param array $properties
	 *
	 * @return self
	 */
	public function modifyProperties(array $properties): self
	{
		foreach($properties as $property => $value)
		{
			$this->modifyProperty($property, $value);
		}

		return $this;
	}
	
	/**
	 * @param string $property
	 * @param mixed $value
	 *
	 * @return self
	 */
	public function setReference(string $property, mixed $value): self
	{
		$this->_references[$property] = $value;

		return $this;
	}

	/**
	 * @param string $property
	 *
	 * @return mixed
	 */
	public function &getReference(string $property): mixed
	{
		if(array_key_exists($property, $this->_references) === false)
		{
			return $this->null;
		}

		return $this->_references[$property];
	}
	
	/**
	 * @param string $property
	 *
	 * @return bool
	 */
	public function hasReference(string $property): bool
	{
		return array_key_exists($property, $this->_references);
	}
	
	/**
	 * @param string $property
	 * @param mixed $value
	 *
	 * @return self
	 */
	public function reference(string $property, mixed $value = null): self
	{
		if($value === null)
		{
			return $this->getReference($property);
		}
		
		return $this->setReference($property, $value);
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
	 * @param callable $callback
	 * @param bool $prepend
	 *
	 * @return self
	 */
	public function addSetter(string $property, callable $callback, bool $prepend = false): self
	{
		if(array_key_exists($property, $this->_setters) === false)
		{
			$this->_setters[$property] = [];
		}
	
		if($prepend)
		{
			array_unshift($this->_setters[$property], $callback);
		}
		else
		{
			array_push($this->_setters[$property], $callback);
		}

		return $this;
	}

	/**
	 * @param string $property
	 * @param callable $callback
	 *
	 * @return self
	 */
	public function removeSetter(string $property, callable $callback): self
	{
		if(array_key_exists($property, $this->_setters) === false) // no setters for this property
		{
			return $this;
		}
		
		is_callable($callback, true, $callableName);
		foreach($this->_setters[$property] as $key => $setter)
		{
			is_callable($setter, true, $setterName);
			
			if($callableName === $setterName)
			{
				unset($this->_setters[$property][$key]);
				
				return $this;
			}
		}
		
		return $this;
	}

	/**
	 * @param string $property
	 * @param callable $callback
	 * @param bool $prepend
	 *
	 * @return self
	 */
	public function addGetter(string $property, callable $callback, bool $prepend = false): self
	{
		if(array_key_exists($property, $this->_getters) === false)
		{
			$this->_getters[$property] = [];
		}
		
		if($prepend)
		{
			array_unshift($this->_getters[$property], $callback);
		}
		else
		{
			array_push($this->_getters[$property], $callback);
		}

		return $this;
	}

	/**
	 * @param string $property
	 * @param callable $callback
	 *
	 * @return self
	 */
	public function removeGetter(string $property, callable $callback): self
	{
		if(array_key_exists($property, $this->_getters) === false) // no getters for this property
		{
			return $this;
		}
		
		is_callable($callback, true, $callableName);
		foreach($this->_getters[$property] as $key => $getter)
		{
			is_callable($getter, true, $getterName);
			
			if($callableName === $getterName)
			{
				unset($this->_getters[$property][$key]);
				
				return $this;
			}
		}
		
		return $this;
	}
	
	/**
	 * @param string $property
	 * @param callable $getter
	 * @param callable $setter
	 * @param bool $prepend
	 *
	 * @return self
	 */
	public function addManipulators(string $property,
		callable $getter, callable $setter, bool $prepend = false): self
	{
		$this->addGetter($property, $getter, $prepend);
		$this->addSetter($property, $setter, $prepend);

		return $this;
	}
	
	/**
	 * Value = property or reference
	 * 
	 * @param string $property
	 *
	 * @return mixed
	 */
	public function __get(string $property): mixed
	{
		return $this->getValue($property);
	}

	/**
	 * Value = property or reference
	 * 
	 * @param string $property
	 *
	 * @return mixed
	 */
	public function getValue(string $property): mixed
	{
		if($value = $this->getReference($property))
		{
			return $value;
		}
		
		$value = $this->getProperty($property);
		if($value === null)
		{
			return null;
		}
		
		// run getter
		if(isset($this->_getters[$property]))
		{
			if(array_key_exists($property, $this->_gettersCache))
			{
				return $this->_gettersCache[$property];
			}
			
			foreach($this->_getters[$property] as $callback)
			{
				$value = $callback($value, $property, $this);
			}
		}
		
		$this->_gettersCache[$property] = $value;
		
		return $value;
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
		$this->setValue($property, $value);
	}

	/**
	 * Should be used to change value on the object
	 * 
	 * @param string $property
	 * @param mixed $value
	 *
	 * @return self
	 */
	public function setValue(string $property, mixed $value): self
	{
		// modify a reference
		if($reference = $this->getReference($property))
		{
			$reference = $value;
			
			return $this;
		}
		
		// modify a property
		// run setters
		if(isset($this->_setters[$property]))
		{
			unset($this->_gettersCache[$property]);
			
			foreach($this->_setters[$property] as $callback)
			{
				$value = $callback($value, $property, $this);
			}	
		}
		
		$this->modifyProperty($property, $value);
		
		return $this;
	}

	/**
	 * @param string $property
	 */
	public function __unset(string $property): void
	{
		if(array_key_exists($property, $this->_properties))
		{
			unset($this->_properties[$property]);
			
			// record the change on update object
			if($this->_updateObject !== null)
			{
				$this->_updateObject->__unset($property);
			}
		}
		else if(array_key_exists($property, $this->_references))
		{
			unset($this->_references[$property]);
		}
	}
	
	/**
	 * Should be used to recreate the model instance from stdClass
	 * All values will be marked as modified, hence the resulting object can be used to perform an update
	 * If you wish to restore a persisted instance with no modifications (for example from session),
	 * set $restore to true or use restore() instead
	 * Warning: references are not reinstantiated, because there is no information about object's class 
	 * 
	 * @param object $source
	 * @param array $skip fields to skip
	 * @param bool $restore
	 *
	 * @return self
	 */
	public static function import(
		object $source,
		array $skip = [],
		bool $restore = false
	): self
	{
		$destination = new static; // late static binding
		foreach($source as $property => $value)
		{
			if(in_array($property, $skip, true) === true)
			{
				continue;
			}
			
			if($restore)
			{
				$destination->setProperty($property, $value);
			}
			else
			{
				$destination->$property = $value;
			}
		}
		
		if($restore)
		{
			$destination->exists(true); // needed by save()
		}

		return $destination;
	}
	
	/**
	 * Should be used to recreate the model instance after storing it for example in session 
	 * 
	 * @param object $source
	 * @param array $skip fields to skip
	 *
	 * @return self
	 */
	public static function restore(object $source, array $skip = []): self
	{
		$destination = self::import($source, $skip, true);

		return $destination;
	}	
	
	/**
	 * Should be used to set multiple values on the object
	 * The values will be let through the setters
	 * This method is the optimal way of setting multiple changes on the object from an array
	 * 
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
	 * Alias of export, but references are not exported by default
	 * 
	 * @param array $skip fields to skip
	 * @param bool $references include references
	 * @param int $type
	 *
	 * @return stdClass|array|ArrayObject
	 */
	public function getValues(array $skip = [],
		bool $references = false,
		int $type = self::EXPORT_TYPE_STDCLASS): stdClass|array|ArrayObject
	{
		return $this->export($skip, $references, $type);
	}

	/**
	 * Exports the object for storage in session or database
	 * 
	 * @param array $skip fields to skip
	 * @param bool $references include references
	 * @param int $type
	 *
	 * @return stdClass|array|ArrayObject
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
			
			$export->$property = $this->getValue($property);
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
		$this->exists(true);

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
		return $this->saveUnique($property, $generator, $attemptsMax);
	}

	/**
	 * @param string $property
	 * @param callable $generator
	 * @param int $attemptsMax
	 *
	 * @return bool
	 */
	public function saveUnique(string $property, callable $generator, int $attemptsMax): bool
	{
		$attempt = 1;
		$lastValue = null;
		while(true)
		{
			try
			{
				$lastValue = $generator($attempt, $lastValue);
				$this->__set($property, $lastValue);
				return $this->save();
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
				$this->setProperty($property, $value);
				//$this->__set($property, $updateObject->__get($property));
			}
			
			// reset modified values
			$this->resetModified();
			
			// reset getters cache
			$this->_gettersCache = [];
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
		//var_dump($this->getModifiedValues());
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
				$this->modifyProperty($property, $value);
			}

			// reset modified values
			$this->resetModified();
			// reset getters cache
			$this->_gettersCache = [];
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
		$this->bindPrimaryKeys($query);

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
	 * @param ?bool $exists
	 *
	 * @return bool
	 */
	public function exists(?bool $exists = null): bool
	{
		if($exists !== null)
		{
			$this->_exists = $exists;
		}
		
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
			$statement->bindValue(':' . $primaryKey, $this->_properties[$primaryKey],
				PDO::PARAM_STR);
		}
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

			$values->{$property} = $this->getValue($property);
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