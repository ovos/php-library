<?php
declare(strict_types=1);

namespace Ovos\Model\Mysql\Template;

use Ovos\Model\Mysql;
use Ovos\Model\Mysql\Template;
use Ovos\Service\Auth;
use Override;

use function Ovos\services;
use function array_merge;

/**
 * Users
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Users extends Template
{
	/**
	 * @var string[]
	 */
	protected array $update = [
		'preInsert' => 'created_by',
		'preUpdate' => 'modified_by'
	];

	/**
	 * @param array $update
	 */
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
		
		$authService = $this->container->get(Auth::SYMBOL);
		if($authService !== null
			&& ($user = $authService->getUser()))
		{
			$model->{$this->update[__FUNCTION__]} = $user->id;
		}
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
		
		$authService = $this->container->get(Auth::SYMBOL);
		if($authService !== null
			&& ($user = $authService->getUser()))
		{
			$model->{$this->update[__FUNCTION__]} = $user->id;
		}
	}
}
