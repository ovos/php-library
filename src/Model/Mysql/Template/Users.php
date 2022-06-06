<?php
declare(strict_types=1);

namespace Ovos\Model\Mysql\Template;

use Ovos\Model\Mysql;
use Ovos\Model\Mysql\Template;

use function Ovos\services;
use function array_merge;

/**
 * Users
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
class Users extends Template
{
	/**
	 * @var string[]
	 */
	protected array $_update = [
		'preInsert' => 'created_by',
		'preUpdate' => 'modified_by'
	];

	/**
	 * @param array $update
	 */
	public function __construct(array $update = [])
	{
		parent::__construct();
		
		$this->_update = array_merge($this->_update, $update);
	}	
	
	/**
	 * @param Mysql $model
	 */
	public function preInsert(Mysql $model): void
	{
		if($this->_update[__FUNCTION__] === null)
		{
			return;
		}
		
		$auth = services()->auth;
		if($auth->isEnabled() && ($user = $auth->getUser()))
		{
			$model->{$this->_update[__FUNCTION__]} = $user->id;
		}
	}

	/**
	 * @param Mysql $model
	 */
	public function preUpdate(Mysql $model): void
	{
		if($this->_update[__FUNCTION__] === null)
		{
			return;
		}
		
		$auth = services()->auth;
		if($auth->isEnabled() && ($user = $auth->getUser()))
		{
			$model->{$this->_update[__FUNCTION__]} = $user->id;
		}
	}
}
