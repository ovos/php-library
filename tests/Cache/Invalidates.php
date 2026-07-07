<?php
declare(strict_types=1);

namespace Tests\Cache;

use Ovos\Cache\Invalidates as Subject;
use Ovos\Model\Mysql;
use Ovos\Test;

use function in_array;

/**
 * A model declaring its cache-invalidation tags
 */
#[Subject('article:{id}', 'articles')]
class InvalidatesArticle extends Mysql
{
	public static function getStoreClass(): string
	{
		return \Ovos\Store\Mysql::class; // unused - expansion never queries
	}
}

/**
 * A child inherits (and may extend) the parent's declarations
 */
#[Subject('featured')]
class InvalidatesFeatured extends InvalidatesArticle
{
}

/**
 * Invalidates - the attribute reader and placeholder expansion
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Invalidates extends Test
{
	public function readsDeclaredPatterns(): bool
	{
		$patterns = Subject::forClass(InvalidatesArticle::class);
		
		return $patterns === ['article:{id}', 'articles'];
	}
	
	public function childInheritsAndExtendsParentPatterns(): bool
	{
		$patterns = Subject::forClass(InvalidatesFeatured::class);
		
		return in_array('featured', $patterns, true) === true
			&& in_array('article:{id}', $patterns, true) === true
			&& in_array('articles', $patterns, true) === true;
	}
	
	public function unannotatedClassYieldsNothing(): bool
	{
		return Subject::forClass(Mysql::class) === [];
	}
	
	public function expandsPlaceholdersFromModelValues(): bool
	{
		$model = new InvalidatesArticle(['id' => 42]);
		
		return Subject::expand($model) === ['article:42', 'articles'];
	}
	
	public function incompletePlaceholdersAreDropped(): bool
	{
		// no id yet (unsaved model) - nothing could have been cached
		// under 'article:{id}', but the list tag still purges
		$model = new InvalidatesArticle;
		
		return Subject::expand($model) === ['articles'];
	}
}
