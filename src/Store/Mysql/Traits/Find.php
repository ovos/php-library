<?php
declare(strict_types=1);

namespace Ovos\Store\Mysql\Traits;

use Ovos\Model;
use PDO;

/**
 * Find
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
trait Find
{
	/**
	 * @return false|Model|Model[]
	 */
	public function find(
		...$arguments,
	): false|Model|array
	{
		$many = $arguments['many'] ?? false;
		unset($arguments['many']);
		
		$query = $this->executeFind(...$arguments);
		
		return $many
			? $query->fetchAll(PDO::FETCH_CLASS, $this->getModel())
			: $query->fetchObject($this->getModel());
	}
}
