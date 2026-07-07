<?php
declare(strict_types=1);

namespace Ovos\Cache;

use Ovos\Model\Mysql as Model;
use Attribute;
use ReflectionClass;

use function array_keys;
use function preg_replace_callback;

/**
 * Invalidates
 *
 * Declares WHICH cache tags a model's changes make stale - once, on the
 * class - and the framework purges them after every successful save or
 * delete (via the base postSave()/postDelete() hooks):
 *
 *   #[Cache\Invalidates('article:{id}', 'articles')]
 *   class Article extends Model\Mysql
 *
 * `{column}` placeholders expand from the model's values at purge time,
 * so a content edit invalidates exactly the pages and fragments tagged
 * with it - zero controller code.
 *
 * NOTE: models overriding postSave()/postDelete() must call parent:: to
 * keep the purge.
 *
 * @author Marcin Gil <mg@ovos.at>
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class Invalidates
{
	/**
	 * [model class => string[] patterns] - parents included, once per process
	 */
	protected static array $cache = [];
	
	/**
	 * @var string[]
	 */
	public readonly array $tags;
	
	public function __construct(
		string ...$tags,
	)
	{
		$this->tags = $tags;
	}
	
	/**
	 * Every tag pattern declared on the class and its parents
	 *
	 * @return string[]
	 */
	public static function forClass(
		string $class,
	): array
	{
		if(isset(self::$cache[$class]) === true)
		{
			return self::$cache[$class];
		}
		
		$patterns = [];
		
		$reflection = new ReflectionClass($class);
		while($reflection !== false)
		{
			foreach($reflection->getAttributes(self::class) as $attribute)
			{
				foreach($attribute->newInstance()->tags as $tag)
				{
					$patterns[$tag] = true; // parents add, duplicates collapse
				}
			}
			
			$reflection = $reflection->getParentClass();
		}
		
		return self::$cache[$class] = array_keys($patterns);
	}
	
	/**
	 * The concrete tags for a model instance: {column} placeholders expand
	 * from its raw values; a pattern whose placeholder is null or '' is
	 * DROPPED (no value means nothing could have been cached under it)
	 *
	 * @return string[]
	 */
	public static function expand(
		Model $model,
	): array
	{
		$tags = [];
		
		foreach(self::forClass($model::class) as $pattern)
		{
			$incomplete = false;
			
			$tag = preg_replace_callback(
				'~\{([A-Za-z0-9_]+)\}~',
				static function(array $match) use ($model, &$incomplete): string
				{
					$value = $model->getProperty($match[1]);
					if($value === null || $value === '')
					{
						$incomplete = true;
						
						return '';
					}
					
					return (string)$value;
				},
				$pattern,
			);
			
			if($incomplete === false)
			{
				$tags[] = $tag;
			}
		}
		
		return $tags;
	}
}
