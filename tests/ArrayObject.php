<?php
declare(strict_types=1);

namespace Tests;

use Ovos\Test;
use Ovos\Arrays;
use Ovos\ArrayObject as BaseArrayObject;

/**
 * ArrayObject
 *
 * @package Tests
 * @author Marcin Gil <mg@ovos.at>
 */
class ArrayObject extends Test
{
	protected BaseArrayObject $_arrayObject;
	
	public function __construct()
	{
		$this->_arrayObject = Arrays::deepToArrayObject([
			'system' => [
				'database' => [
					'type' => 'mysql',
				],
			],
		]);
	}
	
	public function getString(): bool
	{
		return $this->_arrayObject->getPath('system.database.type') === 'mysql';
	}
	
	public function getArray(): bool
	{
		return $this->_arrayObject->getPath(['system', 'database', 'type']) === 'mysql';
	}
	
	public function get(): bool
	{
		$arrayObject = new BaseArrayObject;
		$arrayObject->verified = true;
		
		return $arrayObject->verified === true;
	}
}
