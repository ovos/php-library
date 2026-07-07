<?php
declare(strict_types=1);

namespace Ovos\Model;

/**
 * Relation
 *
 * The single source of a relation's knowledge - fk column, reference
 * name, key column, child store and query scope - declared ONCE on the
 * model class instead of smeared across hand-written store methods.
 * Consumed by Store\Mysql::withRelations() (batched eager loading) and
 * by the model's lazy fallback (Model\Mysql::getValue()).
 *
 * Attributes cannot carry closures, so query shaping is a NAMED SCOPE
 * on the child store: scope: 'activeCompetences' calls
 * $store->scopeActiveCompetences($query).
 *
 * @author Marcin Gil <mg@ovos.at>
 */
abstract class Relation
{
	public function __construct(
		public readonly string $name, // the reference name on the parent
		public readonly string $model, // child model class
		public readonly ?string $store = null, // child store; default: the model's own
		public readonly ?string $scope = null, // named scope on the child store
		public readonly bool $lazy = true, // unloaded access fetches (and warns)
	)
	{
	}
	
	/**
	 * The store loading this relation - explicitly declared, or the
	 * child model's own store (Model::getStoreClass())
	 */
	public function storeClass(): string
	{
		return $this->store ?? ($this->model)::getStoreClass();
	}
}
