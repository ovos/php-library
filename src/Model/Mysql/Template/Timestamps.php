<?php
declare(strict_types=1);

namespace Ovos\Model\Mysql\Template;

use Ovos\Model\Mysql;
use Ovos\Model\Mysql\Template;
use Ovos\Pdo\Expression;
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
	protected array $_update = [
		'preInsert' => 'created_at',
		'preUpdate' => 'modified_at'
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
		
		$model->{$this->_update[__FUNCTION__]} = new Expression('NOW()');
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
		
		$model->{$this->_update[__FUNCTION__]} = new Expression('NOW()');
	}
}
