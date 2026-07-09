<?php
declare(strict_types=1);

namespace Tests;

use Ovos\Test;
use Ovos\Arrays;
use Ovos\ArrayObject as BaseArrayObject;

use function json_encode;

/**
 * ArrayObject
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class ArrayObject extends Test
{
	protected BaseArrayObject $arrayObject;
	
	public function __construct()
	{
		$this->arrayObject = Arrays::deepToArrayObject([
			'system' => [
				'database' => [
					'type' => 'mysql',
				],
			],
		]);
	}
	
	public function getString(): bool
	{
		return $this->arrayObject->getPath('system.database.type') === 'mysql';
	}
	
	public function getArray(): bool
	{
		return $this->arrayObject->getPath(['system', 'database', 'type']) === 'mysql';
	}
	
	public function get(): bool
	{
		$arrayObject = new BaseArrayObject;
		$arrayObject->verified = true;
		
		return $arrayObject->verified === true;
	}
	
	public function jsonSerialize(): bool
	{
		// a map stays a JSON object; a list stays a JSON array (a bare
		// ArrayObject would force it to an object, "0"/"1"/... keyed); an
		// empty one is an array; nested ArrayObjects follow the same rule
		$map = new BaseArrayObject(['a' => 1, 'b' => ['c' => 2]]);
		$list = new BaseArrayObject([1, 2, 3]);
		$empty = new BaseArrayObject;
		
		return json_encode($map) === '{"a":1,"b":{"c":2}}'
			&& json_encode($list) === '[1,2,3]'
			&& json_encode($empty) === '[]';
	}
}
