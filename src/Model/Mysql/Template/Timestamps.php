<?php
declare(strict_types=1);

namespace Ovos\Model\Mysql\Template;

use Ovos\Model\Mysql;
use Ovos\Model\Mysql\Template;
use Ovos\Pdo\Expression;

/**
 * Template
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
class Timestamps extends Template
{
	/**
	 * @param Mysql $model
	 */
	public function preInsert(Mysql $model): void
	{
		$model->created_at = new Expression('NOW()');
	}

	/**
	 * @param Mysql $model
	 */
	public function preUpdate(Mysql $model): void
	{
		$model->modified_at = new Expression('NOW()');
	}
}
