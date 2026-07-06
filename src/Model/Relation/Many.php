<?php
declare(strict_types=1);

namespace Ovos\Model\Relation;

use Ovos\Model\Relation;
use Attribute;

/**
 * Many
 *
 * One parent, many children: the children carry the parent's id in
 * their "by" column and are assigned to the parent's reference keyed
 * by their "key" column - the assignByReference() convention.
 *
 *   #[Relation\Many('Competences', JobCompetence::class,
 *       by: 'job_id', key: 'competence_id',
 *       store: JobsCompetences::class, scope: 'activeCompetences')]
 *
 * @author Marcin Gil <mg@ovos.at>
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
class Many extends Relation
{
	public function __construct(
		string $name,
		string $model,
		public readonly string $by, // fk column on the CHILD → parent id
		public readonly string $key, // child column keying the reference array
		?string $store = null, // default: the child model's own store
		?string $scope = null,
		bool $lazy = true,
	)
	{
		parent::__construct($name, $model, $store, $scope, $lazy);
	}
}
