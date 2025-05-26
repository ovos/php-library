<?php
declare(strict_types=1);

namespace Tests;

use Ovos\Test;
use Ovos\Arrays as Arrays;
use Ovos\ArrayObject as BaseArrayObject;

/**
 * ArrayObject
 *
 * @package Tests
 * @author Marcin Gil <mg@ovos.at>
 */
class ArrayObject extends Test
{
	public function get(): bool
	{
		$arrayObject = Arrays::deepToArrayObject([
			'system' => [
				'database' => [
					'type' => 'mysql',
				],
			],
		]);
		
		return $arrayObject->get('system.database.type') === 'mysql';
	}
}
