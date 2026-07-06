<?php
declare(strict_types=1);

namespace Ovos\Model;

use Ovos\Exception;
use Closure;
use ReflectionAttribute;
use ReflectionClass;

use function method_exists;
use function ucfirst;

/**
 * Relations
 *
 * Reads a model class's declared Relation attributes - once per class
 * per process, parents included (a base model's relations apply to
 * every child that does not redeclare the name).
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Relations
{
	/**
	 * [model class => [name => Relation]]
	 */
	protected static array $cache = [];
	
	/**
	 * Every relation declared on the class (and its parents), by name
	 *
	 * @return array<string, Relation>
	 */
	public static function forClass(
		string $class,
	): array
	{
		if(isset(self::$cache[$class]))
		{
			return self::$cache[$class];
		}
		
		$relations = [];
		$reflection = new ReflectionClass($class);
		while($reflection !== false)
		{
			foreach($reflection->getAttributes(Relation::class,
				ReflectionAttribute::IS_INSTANCEOF) as $attribute)
			{
				/** @var Relation $relation */
				$relation = $attribute->newInstance();
				
				// a child's declaration wins over a parent's
				$relations[$relation->name] ??= $relation;
			}
			
			$reflection = $reflection->getParentClass();
		}
		
		return self::$cache[$class] = $relations;
	}
	
	public static function get(
		string $class,
		string $name,
	): ?Relation
	{
		return self::forClass($class)[$name] ?? null;
	}
	
	/**
	 * The declared relation or a loud failure - for call sites where a
	 * typo must not silently load nothing
	 */
	public static function require(
		string $class,
		string $name,
	): Relation
	{
		$relation = self::get($class, $name);
		if($relation === null)
		{
			throw new Exception('Model "' . $class
				. '" declares no relation "' . $name . '".');
		}
		
		return $relation;
	}
	
	/**
	 * The query callback for a relation load: the declared NAMED scope
	 * on the child store (attributes cannot carry closures), then an
	 * optional per-call override
	 */
	public static function callback(
		object $store,
		Relation $relation,
		?Closure $override = null,
	): ?Closure
	{
		if($relation->scope === null && $override === null)
		{
			return null;
		}
		
		return static function(object $query) use ($store, $relation, $override): void
		{
			if($relation->scope !== null)
			{
				$method = 'scope' . ucfirst($relation->scope);
				if(method_exists($store, $method) === false)
				{
					throw new Exception('Store "' . $store::class
						. '" is missing the relation scope "' . $method . '()".');
				}
				$store->$method($query);
			}
			
			if($override !== null)
			{
				$override($query);
			}
		};
	}
}
