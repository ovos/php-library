<?php
declare(strict_types=1);

namespace Ovos;

use ArrayObject;

use function is_array;
use function count;

/**
 * Arrays
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
class Arrays
{
	/**
	 * @param array $array
	 * @param string $className
	 * @param int $flags
	 *
	 * @return mixed
	 */
	public static function deepToArrayObject(array $array,
		string $className = ArrayObject::class,
		int $flags = ArrayObject::ARRAY_AS_PROPS): mixed
	{
		foreach($array as $key => $value)
		{
			if(is_array($value) === false)
			{
				continue;
			}
			
			$array[$key] = self::deepToArrayObject($value, $className, $flags);
		}

		return new $className($array, $flags);
	}
	
	/*
	 * arrayDeepMerge
	 * @see code from php at moechofe dot com (array_merge comment on php.net)
	 *
	 * array arrayDeepMerge ( array array1 [, array array2 [, array ...]] )
	 *
	 * Like array_merge
	 *
	 * arrayDeepMerge() merges the elements of one or more arrays together so
	 * that the values of one are appended to the end of the previous one. It
	 * returns the resulting array.
	 * 
	 * If the input arrays have the same string keys, then the later value for
	 * that key will overwrite the previous one. If, however, the arrays contain
	 * numeric keys, the later value will not overwrite the original value, but
	 * will be appended.
	 * 
	 * If only one array is given and the array is numerically indexed, the keys
	 * get reindexed in a continuous way.
	 *
	 * Different from array_merge
	 * If string keys have arrays for values, these arrays will merge recursively.
	 */
	public static function deepMerge(...$arrays)
	{
		switch(count($arrays))
		{
			case 0:
				return false;

			case 1:
				return $arrays[0];

			case 2:
				$arrays[2] = [];
				
				if(is_array($arrays[0])  === false
					|| is_array($arrays[1]) === false)
				{
					return $arrays[1];
				}
				
				foreach(array_unique(array_merge(array_keys($arrays[0]), array_keys($arrays[1]))) as $key)
				{
					$isKey0 = array_key_exists($key, $arrays[0]);
					$isKey1 = array_key_exists($key, $arrays[1]);

					if($isKey0 && $isKey1 && is_array($arrays[0][$key]) && is_array($arrays[1][$key]))
					{
						$arrays[2][$key] = (__METHOD__)($arrays[0][$key], $arrays[1][$key]);
					}
					else if($isKey0 && $isKey1)
					{
						$arrays[2][$key] = $arrays[1][$key];
					}
					else if(!$isKey1)
					{
						$arrays[2][$key] = $arrays[0][$key];
					}
					else if(!$isKey0)
					{
						$arrays[2][$key] = $arrays[1][$key];
					}
				}

				return $arrays[2];
				
			default: // merge first two and repeat until there are just two left
				$arrays[1] = (__METHOD__)($arrays[0], $arrays[1]);
				array_shift($arrays);

				return (__METHOD__)(...$arrays);

			break;
		}
	}
	
	/**
	 * Group values in pairs
	 * 
	 * name => value
	 * 
	 * @param array $values
	 *
	 * @return array
	 */
	public static function getPairs(array $values): array
	{
		$paired = [];

		$count = count($values);
		for($i = 0; $i < $count; $i+=2)
		{
			if($i % 2 === 0
				&& isset($values[$i + 1])) // param pairs
			{
				$paired[$values[$i]] = $values[$i + 1];
			}
		}

		return $paired;
	}

	/**
	 * Returns a flattened array
	 *
	 * example:
	 * 		[('Data' => ['first_name' => 'Heniek']]
	 * will be transformed to
	 * 		['Data[first_name]' => 'Heniek']
	 *
	 * @param array $array
	 * @return array
	 */
	public static function flatten(array $array): array
	{
		$flat = [];
		foreach($array as $key => $value)
		{
			if(is_array($value) === false)
			{
				$flat[$key] = $value;
				continue;
			}
			
			$value = self::flatten($value);
			foreach($value as $column => $columnValue)
			{
				if($arrayColumn = strstr($column, '['))
				{
					$column = '[' . str_replace($arrayColumn, '', $column) . ']' . $arrayColumn;
				}
				else
				{
					$column = '[' . $column . ']';
				}
				
				$flat[$key . $column] = $columnValue;
			}

		}

		return $flat;
	}
	
	/**
	 * @param string $prefix
	 * @param array $values
	 *
	 * @return array
	 */
	public static function prefixValues(string $prefix, array $values): array
	{
		return array_map(static fn($value) => $prefix . $value, $values);
	}
	
	/**
	 * @param string $prefix
	 * @param array $values
	 *
	 * @return array
	 */
	public static function prefixKeys(string $prefix, array $values): array
	{
		return array_combine
		(
			array_map(static fn($key) => $prefix . $key, array_keys($values)),
			$values
		);
	}
}
