<?php
declare(strict_types=1);

namespace Ovos\Model\Mysql\Template;

use Ovos\Model\Mysql;
use Ovos\Model\Mysql\Template;
use Ovos\Pdo\Expression;
use Override;

use function array_merge;

/**
 * Template
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
class Timestamps extends Template
{
	/**
	 * @var string[]
	 */
	protected array $update = [
		'preInsert' => 'created_at',
		'preUpdate' => 'modified_at'
	];
	
	public function __construct(
		array $update = [],
	)
	{
		parent::__construct();
		
		$this->update = array_merge($this->update, $update);
	}
	
	#[Override]
	public function preInsert(
		Mysql $model,
	): void
	{
		if($this->update[__FUNCTION__] === null)
		{
			return;
		}
		
		$model->{$this->update[__FUNCTION__]}
			= new Expression('NOW()');
	}
	
	#[Override]
	public function preUpdate(
		Mysql $model,
	): void
	{
		if($this->update[__FUNCTION__] === null)
		{
			return;
		}
		
		$model->{$this->update[__FUNCTION__]}
			= new Expression('NOW()');
	}
}
