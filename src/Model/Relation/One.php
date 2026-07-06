<?php
declare(strict_types=1);

namespace Ovos\Model\Relation;

use Ovos\Model\Relation;
use Attribute;

/**
 * One
 *
 * The parent points at a single child: the parent's "on" column holds
 * the child's "key" value (default: its id), and the child MODEL
 * itself is assigned to the reference.
 *
 *   #[Relation\One('Category', Category::class,
 *       on: 'category_id', store: Categories::class)]
 *
 * @author Marcin Gil <mg@ovos.at>
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
class One extends Relation
{
	public function __construct(
		string $name,
		string $model,
		public readonly string $on, // fk column on the PARENT → child key
		?string $store = null, // default: the child model's own store
		?string $scope = null,
		public readonly string $key = 'id', // the child column "on" points at
		bool $lazy = true,
	)
	{
		parent::__construct($name, $model, $store, $scope, $lazy);
	}
}
