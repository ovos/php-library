<?php
declare(strict_types=1);

namespace Ovos\Route;

use ReflectionClass;

use function array_keys;

/**
 * Slugs
 *
 * The registry of slug-routable models: reads the Route\Slug attribute
 * off a given set of model classes, keyed by URL prefix. Because
 * routing goes URL -> entity, the routable models must be known upfront
 * (unlike relations, read per-model on demand) - the set is seeded from
 * config ("system.routes.slug.models"); the URL SHAPE still derives
 * from the attribute. Read once per process.
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Slugs
{
	/**
	 * [prefix => [model => class-string, slug => Slug]]
	 */
	protected array $byPrefix = [];
	
	/**
	 * @param string[] $models routable model class names
	 */
	public function __construct(
		array $models,
	)
	{
		foreach($models as $model)
		{
			$reflection = new ReflectionClass($model);
			foreach($reflection->getAttributes(Slug::class) as $attribute)
			{
				/** @var Slug $slug */
				$slug = $attribute->newInstance();
				
				// first declaration of a prefix wins - a collision is a
				// project config error, not something to silently merge
				$this->byPrefix[$slug->prefix] ??= [
					'model' => $model,
					'slug' => $slug,
				];
			}
		}
	}
	
	/**
	 * The routable model + its Slug for a URL prefix, or null
	 *
	 * @return array{model: class-string, slug: Slug}|null
	 */
	public function forPrefix(
		string $prefix,
	): ?array
	{
		return $this->byPrefix[$prefix] ?? null;
	}
	
	public function prefixes(): array
	{
		return array_keys($this->byPrefix);
	}
}
