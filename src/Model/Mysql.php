<?php
declare(strict_types=1);

namespace Ovos\Model;

use Ovos\ArrayObject;
use Ovos\Connections;
use Ovos\Console;
use Ovos\Exception;
use Ovos\Model;
use Ovos\Model\Relation;
use Ovos\Model\Relation\Many as RelationMany;
use Ovos\Model\Relation\One as RelationOne;
use Ovos\Model\Relations;
use Ovos\Store\Mysql as Store;
use Ovos\Model\Mysql\Template;
use Countable;
use Iterator;
use JsonSerializable;
use PDO;
use PDOStatement;
use stdClass;
use Throwable;

use function array_key_exists;
use function array_keys;
use function array_push;
use function array_unshift;
use function count;
use function current;
use function in_array;
use function is_callable;
use function key;
use function next;
use function reset;

/**
 * Mysql
 *
 * @author Marcin Gil <mg@ovos.at>
 *
 * @property string $created_at
 * @property string $modified_at
 */
abstract class Mysql
	extends Model
	implements Iterator, Countable, JsonSerializable
{
	// Exports
	public const int EXPORT_TYPE_STDCLASS = 0;
	public const int EXPORT_TYPE_ARRAY = 1;
	public const int EXPORT_TYPE_ARRAYOBJECT = 2;
	
	// Filters
	public const int FILTER_MODE_IN = 0;
	public const int FILTER_MODE_OUT = 1;
	
	/**
	 * Return null by reference
	 */
	public mixed $null = null;
	
	protected string $sourceName = 'mysql';
	
	/* Some properties use undescores to prevent conflicts with model properties */
	
	/**
	 * A connection between PHP and a database server
	 */
	protected ?PDO $_source = null;
	
	/**
	 * List of primary keys
	 */
	protected array $primaryKeys = ['id'];
	
	/**
	 * Autoincrement key
	 */
	protected ?string $autoIncrementKey = 'id';
	
	protected array $_templates = [];
	protected array $_setters = [];
	protected array $_getters = [];
	protected array $_gettersCache = [];
	protected array $_properties = [];
	protected array $_modified = [];
	protected array $_references = [];
	
	/* A record exists if it was fetched with PK, otherwise it's considered new */
	protected bool $_exists = false;
	protected ?self $_updateObject = null;
	
	/**
	 * Filter in or out properties in jsonSerialize
	 *
	 * Useful in case of:
	 * - we do not wish JSON to serialize binary fields (e.g., binary value like POINT in MySQL)
	 */
	protected ?array $_jsonSerializeFilter = null;
	protected int $_jsonSerializeFilterMode = self::FILTER_MODE_OUT;
	
	public function __construct(
		?array $properties = null,
	)
	{
		parent::__construct();
		
		$this->_exists = $this->primaryKeysLoaded();
		
		if($properties !== null)
		{
			$this->fromArray($properties);
		}
		
		// reset modified values (after initializing the object by PDO)
		$this->resetModified();
		
		// set up whatever needs to be set
		$this->triggerEvents('setUp');
	}
	
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
	public function source(): PDO
	{
		return $this->getSource();
	}
	
	public function getPrimaryKeys(): array
	{
		return $this->primaryKeys;
	}
	
	public function getAutoIncrementKey(): ?string
	{
		return $this->autoIncrementKey;
	}
	
	public function setJsonSerializeFilter(
		array $filter,
	): static
	{
		$this->_jsonSerializeFilter = $filter;
		
		return $this;
	}
	
	public function setJsonSerializeFilterMode(
		int $filterMode,
	): static
	{
		$this->_jsonSerializeFilterMode = $filterMode;
		
		return $this;
	}
	
	/**
	 * Should be used when initializing multiple properties at once.
	 * The change will not trigger setters
	 * and will not be recorded as a modification.
	 * Used for restoring model's state.
	 */
	public function setProperties(
		array $properties,
	): static
	{
		foreach($properties as $property => $value)
		{
			$this->setProperty($property, $value);
		}
		
		return $this;
	}
	
	public function getProperties(): array
	{
		return $this->_properties;
	}
	
	/**
	 * Should be used to initialize a single property.
	 * The change will not trigger setters
	 * and will not be recorded as a modification.
	 * Used for restoring model's state.
	 */
	public function setProperty(
		string $property,
		mixed $value,
	): static
	{
		$this->_properties[$property] = $value;
		
		return $this;
	}
	
	public function getProperty(
		string $property,
	): mixed
	{
		if(array_key_exists($property, $this->_properties) === false)
		{
			return null;
		}
		
		return $this->_properties[$property];
	}
	
	/**
	 * Should be used to modify a single property, while skipping the setters;
	 * The change will be recorded as modification
	 */
	public function modifyProperty(
		string $property,
		mixed $value,
	): static
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
		
		// record the change on an update object
		$this->_updateObject?->modifyProperty($property, $value);
		
		return $this;
	}
	
	/**
	 * Should be used to modify multiple properties at once, while skipping the setters
	 * The change will be recorded as modification.
	 */
	public function modifyProperties(
		array $properties,
	): static
	{
		foreach($properties as $property => $value)
		{
			$this->modifyProperty($property, $value);
		}
		
		return $this;
	}
	
	public function setReference(
		string $property,
		mixed $value,
	): static
	{
		$this->_references[$property] = $value;
		
		return $this;
	}
	
	public function &getReference(
		string $property,
	): mixed
	{
		if(array_key_exists($property, $this->_references) === false)
		{
			return $this->null;
		}
		
		return $this->_references[$property];
	}
	
	/**
	 * Fetches a declared relation for THIS model only and assigns it -
	 * the lazy fallback behind unloaded reference reads. Correctness
	 * net, not the habit: batch sets of models with withRelations()
	 */
	public function loadRelation(
		Relation $relation,
	): mixed
	{
		$store = new ($relation->storeClass());
		$callback = Relations::callback($store, $relation);
		
		if($relation instanceof RelationMany)
		{
			$value = [];
			foreach($store->fetchByReference([$this->id => $this],
				$relation->by, $relation->model, $callback) as $child)
			{
				$value[$child->{$relation->key}] = $child;
			}
		}
		else
		{
			/** @var RelationOne $relation */
			$value = null;
			if($this->{$relation->on} !== null)
			{
				$children = $store->fetchByReference(
					[$this->{$relation->on} => $this],
					$relation->key, $relation->model, $callback);
				$value = $children[0] ?? null;
			}
		}
		
		$this->setReference($relation->name, $value);
		
		// surface it in the dev console (profiler panel) - a lazy load
		// in a loop is exactly the N+1 the batched path exists to avoid
		try
		{
			$this->container->getClass(Console::class)
				->setMessage('lazy relation load: ' . static::class
					. '.' . $relation->name . ' - batch with withRelations()');
		}
		catch(Throwable)
		{
			// no container/console in this context - the load still counts
		}
		
		return $value;
	}
	
	public function hasReference(
		string $property,
	): bool
	{
		return array_key_exists($property, $this->_references);
	}
	
	public function reference(
		string $property,
		mixed $value = null,
	): static
	{
		if($value === null)
		{
			return $this->getReference($property);
		}
		
		return $this->setReference($property, $value);
	}
	
	public function setModified(
		array $modified,
	): static
	{
		$this->_modified = $modified;
		
		return $this;
	}
	
	public function getModified(): array
	{
		return $this->_modified;
	}
	
	public function isModified(
		string ...$properties,
	): bool
	{
		if(count($properties) === 0)
		{
			return count($this->_modified) > 0;
		}
		
		// check if any of the properties passed was modified
		foreach($properties as $property)
		{
			if(array_key_exists($property, $this->_modified) !== false)
			{
				return true;
			}
		}
		
		return false;
	}
	
	public function resetModified(): static
	{
		$this->_modified = [];
		
		return $this;
	}
	
	public function setUpdateObject(
		?self $updateObject,
	): static
	{
		$this->_updateObject = $updateObject;
		
		return $this;
	}
	
	public function getUpdateObject(): ?static
	{
		return $this->_updateObject;
	}
	
	public function addTemplate(
		Template $template,
	): static
	{
		$this->_templates[] = $template;
		
		return $this;
	}
	
	public function getTemplates(): array
	{
		return $this->_templates;
	}
	
	public function addSetter(
		string $property,
		callable $callback,
		bool $prepend = false,
	): static
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
	
	public function removeSetter(
		string $property,
		callable $callback,
	): static
	{
		// no setters for this property
		if(array_key_exists($property, $this->_setters) === false)
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
	
	public function addGetter(
		string $property,
		callable $callback,
		bool $prepend = false,
	): static
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
			$this->_getters[$property][] = $callback;
		}
		
		return $this;
	}
	
	public function removeGetter(
		string $property,
		callable $callback,
	): static
	{
		// no getters for this property
		if(array_key_exists($property, $this->_getters) === false)
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
	
	public function addManipulators(
		string $property,
		callable $getter,
		callable $setter,
		bool $prepend = false,
	): static
	{
		$this->addGetter($property, $getter, $prepend);
		$this->addSetter($property, $setter, $prepend);
		
		return $this;
	}
	
	/**
	 * Value = property or reference
	 */
	public function __get(
		string $property,
	): mixed
	{
		return $this->getValue($property);
	}
	
	/**
	 * Value = property or reference
	 */
	public function getValue(
		string $property,
	): mixed
	{
		// a SET reference answers even when empty - a loaded-empty []
		// (or a One relation resolved to null) is an answer, not a miss;
		// the old falsy check made it fall through to the property
		if($this->hasReference($property) === true)
		{
			return $this->getReference($property);
		}
		
		// a DECLARED, never-loaded relation loads lazily; inside a loop
		// this is the N+1 that withRelations() prevents - hence the note
		if(($relation = Relations::get(static::class, $property)) !== null
			&& $relation->lazy === true)
		{
			return $this->loadRelation($relation);
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
	
	public function __isset(
		string $property,
	): bool
	{
		return array_key_exists($property, $this->_properties)
			|| array_key_exists($property, $this->_references);
	}
	
	/**
	 * Called also by PDO on FETCH_CLASS
	 */
	public function __set(
		string $property,
		mixed $value,
	): void
	{
		$this->setValue($property, $value);
	}
	
	/**
	 * Should be used to change the value on the object
	 */
	public function setValue(
		string $property,
		mixed $value,
	): static
	{
		// modify a reference
		if($this->hasReference($property))
		{
			$this->_references[$property] = $value;
			
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
	
	public function __unset(
		string $property,
	): void
	{
		if(array_key_exists($property, $this->_properties))
		{
			unset($this->_properties[$property]);
			
			// record the change on an update object
			$this->_updateObject?->__unset($property);
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
	 */
	public static function import(
		object|iterable $source,
		?array $filter = null, // fields to preserve or skip (depending on the filter mode)
		int $filterMode = self::FILTER_MODE_OUT,
		bool $restore = false,
		bool $exists = false,
	): static
	{
		$destination = new static; // late static binding
		foreach($source as $property => $value)
		{
			if($filter !== null
				&& in_array($property, $filter, true)
				=== ($filterMode === self::FILTER_MODE_OUT))
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
		
		if($exists)
		{
			$destination->exists(true); // needed by save()
		}
		
		return $destination;
	}
	
	/**
	 * Should be used to recreate the model instance after storing it for example in session
	 */
	public static function restore(
		object|iterable $source,
		?array $filter = null, // fields to preserve or skip (depending on the filter mode)
		int $filterMode = self::FILTER_MODE_IN,
	): static
	{
		return self::import(
			source: $source,
			filter: $filter,
			filterMode: $filterMode,
			restore: true,
			exists: true,
		);
	}
	
	/**
	 * Fills an instance with values
	 * Should be used to set multiple values on the object
	 * The values will be let through the setters
	 * This method is the optimal way of setting multiple changes on the object from an array
	 */
	public function fromArray(
		array $values,
	): static
	{
		foreach($values as $property => $value)
		{
			$this->$property = $value;
		}
		
		return $this;
	}
	
	/**
	 * Alias of export, but references are not exported by default
	 */
	public function getValues(
		?array $filter = null, // fields to preserve or skip (depending on the filter mode)
		int $filterMode = self::FILTER_MODE_IN,
		bool $references = false, // include references
		int $type = self::EXPORT_TYPE_STDCLASS,
	): stdClass|array|ArrayObject
	{
		return $this->export(
			filter: $filter,
			filterMode: $filterMode,
			references: $references,
			type: $type,
		);
	}
	
	/**
	 * Exports the object for storage in session or database
	 *
	 * Supports filtering, useful in case of:
	 * - we wish to protect some sensitive values, for example, when retuning a JSON object in a response
	 */
	public function export(
		?array $filter = null, // fields to preserve or skip (depending on the filter mode)
		int $filterMode = self::FILTER_MODE_IN,
		bool $references = true, // include references
		int $type = self::EXPORT_TYPE_STDCLASS,
	): stdClass|array|ArrayObject
	{
		$export = new stdClass;
		
		foreach($this as $property => $value)
		{
			if($filter !== null
				&& in_array($property, $filter, true)
				=== ($filterMode === self::FILTER_MODE_OUT))
			{
				continue;
			}
			
			$export->$property = $this->getValue($property);
		}
		
		if($references)
		{
			$this->exportReferences(
				export: $export,
				filter: $filter,
				filterMode: $filterMode,
				type: $type,
			);
		}
		
		return $this->getExportType($export, $type);
	}
	
	protected function exportReferences(
		stdClass $export,
		?array $filter = null, // fields to preserve or skip (depending on the filter mode)
		int $filterMode = self::FILTER_MODE_IN,
		int $type = self::EXPORT_TYPE_STDCLASS,
	): void
	{
		foreach($this->_references as $reference => $value)
		{
			if($filter !== null
				&& in_array($reference, $filter, true)
				=== ($filterMode === self::FILTER_MODE_OUT))
			{
				continue;
			}
			
			// passed as a key => children
			$filterReference = $filter !== null && isset($filter[$reference])
				? $filter[$reference] : null;
			
			$exportReferences = $this->__get($reference);
			// relation to many
			if(is_array($exportReferences))
			{
				foreach($exportReferences as &$exportReference)
				{
					/**
					 * @var self $exportReference
					 */
					$exportReference = $exportReference->export(
						filter: $filterReference,
						filterMode: $filterMode,
						references: true,
						type: $type,
					);
				}
				unset($exportReference);
			}
			// relation to one
			else
			{
				/**
				 * @var self $exportReferences
				 */
				$exportReferences = $exportReferences->export(
					filter: $filterReference,
					filterMode: $filterMode,
					references: true,
					type: $type,
				);
			}
			
			$export->$reference = $exportReferences;
		}
	}
	
	public function toArray(
		?array $filter = null, // fields to preserve or skip (depending on the filter mode)
		int $filterMode = self::FILTER_MODE_IN,
		bool $references = true,
	): array
	{
		return $this->export(
			filter: $filter,
			filterMode: $filterMode,
			references: $references,
			type: self::EXPORT_TYPE_ARRAY,
		);
	}
	
	protected function getExportType(
		stdClass $object,
		int $type,
	): stdClass|array|ArrayObject
	{
		return match($type)
		{
			self::EXPORT_TYPE_STDCLASS => $object,
			self::EXPORT_TYPE_ARRAY => (array)$object,
			self::EXPORT_TYPE_ARRAYOBJECT => new ArrayObject((array)$object),
		};
	}
	
	public function __serialize(): array
	{
		$properties = parent::__serialize();
		unset($properties['_source']);
		
		return $properties;
	}
	
	public function __debugInfo(): array
	{
		return $this->export(
			references: true,
			type: self::EXPORT_TYPE_ARRAY,
		);
	}
	
	public function jsonSerialize(): stdClass
	{
		return $this->export(
			filter: $this->_jsonSerializeFilter,
			filterMode: $this->_jsonSerializeFilterMode,
			references: true,
			type: self::EXPORT_TYPE_STDCLASS,
		);
	}
	
	public function count(): int
	{
		return count($this->_properties);
	}
	
	public function rewind(): void
	{
		reset($this->_properties);
	}
	
	public function current(): mixed
	{
		return current($this->_properties);
	}
	
	public function next(): void
	{
		next($this->_properties);
	}
	
	public function key(): null|int|string
	{
		return key($this->_properties);
	}
	
	public function valid(): bool
	{
		$key = $this->key();
		
		return $key !== null;
	}
	
	public function insert(): bool
	{
		$storeClass = static::getStoreClass();
		/** @var Store $store */
		$store = new $storeClass;
		
		$this->triggerEvents('preInsert', 'preSave');
		$query = $store->insertQuery($this);
		
		$result = $query->execute();
		if($this->autoIncrementKey)
		{
			$this->{$this->autoIncrementKey}
				= (int)$this->source()->lastInsertId();
		}
		
		// reset modified values
		$this->resetModified();
		$this->exists(true);
		
		if($result)
		{
			$this->triggerEvents('postInsert', 'postSave');
		}
		
		return $result;
	}
	
	public function insertUnique(
		string $property,
		callable $generator,
		int $attemptsMax,
	): bool
	{
		return $this->saveUnique($property, $generator, $attemptsMax);
	}
	
	public function saveUnique(
		string $property,
		callable $generator,
		int $attemptsMax,
	): bool
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
	
	public function update(
		self $updateObject,
	): bool
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
			}
			
			// reset modified values
			$this->resetModified();
			
			// reset getters cache
			$this->_gettersCache = [];
			
			$this->triggerEvents('postUpdate', 'postSave');
		}
		
		return $result;
	}
	
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
	
	public function delete(): bool
	{
		$this->triggerEvents('preDelete');
		// refresh only loaded fields
		$query = $this->source()->prepare('
			DELETE FROM ' . self::getTable() . '
			WHERE ' . $this->getPrimaryKeysConditions()
		);
		$this->bindPrimaryKeys($query);
		$result = $query->execute();
		
		if($result)
		{
			$this->triggerEvents('postDelete');
		}
		
		return $result;
	}
	
	public static function getTable(): string
	{
		$storeClass = static::getStoreClass();
		
		/** @var Store $storeClass */
		return $storeClass::getTable();
	}
	
	public function exists(
		?bool $exists = null,
	): bool
	{
		if($exists !== null)
		{
			$this->_exists = $exists;
		}
		
		return $this->_exists;
	}
	
	/**
	 * Can be called only after population by PDO::FETCH_CLASS
	 */
	protected function primaryKeysLoaded(): bool
	{
		foreach($this->primaryKeys as $primaryKey)
		{
			if(empty($this->_properties[$primaryKey]))
			{
				return false;
			}
		}
		
		return true;
	}
	
	public function getPrimaryKeysConditions(): string
	{
		if($this->exists() === false)
		{
			throw new Exception(
				'Primary keys have to be selected for update.');
		}
		
		$conditions = [];
		foreach($this->primaryKeys as $primaryKey)
		{
			$conditions[]
				= sprintf('%s = :%s', $primaryKey, $primaryKey);
		}
		
		return implode(' AND ', $conditions);
	}
	
	public function bindPrimaryKeys(
		PDOStatement $statement,
	): void
	{
		foreach($this->primaryKeys as $primaryKey)
		{
			$statement->bindValue(':' . $primaryKey,
				$this->_properties[$primaryKey],
				PDO::PARAM_STR,
			);
		}
	}
	
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
	
	public function triggerEvents(
		...$events,
	): void
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
	
	public function getErrorMessage(): ?string
	{
		// element [2] is null when there is no error
		return $this->getSource()->errorInfo()[2];
	}
	
	public function setUp(): void
	{
	}
	
	public function preInsert(): void
	{
	}
	
	public function preUpdate(): void
	{
	}
	
	public function preSave(): void
	{
	}
	
	public function preDelete(): void
	{
	}
	
	public function postInsert(): void
	{
	}
	
	public function postUpdate(): void
	{
	}
	
	public function postSave(): void
	{
	}
	
	public function postDelete(): void
	{
	}
}