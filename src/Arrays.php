<?php
declare(strict_types=1);

namespace Ovos;

use ArrayObject;
use function func_num_args;
use function func_get_args;
use function is_array;
use function call_user_func_array;
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
			if(is_array($value))
			{
				$array[$key] = self::deepToArrayObject($value, $className, $flags);
			}
		}

		return new $className($array, $flags);
	}

	// code from php at moechofe dot com (array_merge comment on php.net)
	/*
	 * arrayDeepMerge
	 *
	 * array arrayDeepMerge ( array array1 [, array array2 [, array ...]] )
	 *
	 * Like array_merge
	 *
	 *  arrayDeepMerge() merges the elements of one or more arrays together so
	 * that the values of one are appended to the end of the previous one. It
	 * returns the resulting array.
	 *  If the input arrays have the same string keys, then the later value for
	 * that key will overwrite the previous one. If, however, the arrays contain
	 * numeric keys, the later value will not overwrite the original value, but
	 * will be appended.
	 *  If only one array is given and the array is numerically indexed, the keys
	 * get reindexed in a continuous way.
	 *
	 * Different from array_merge
	 *  If string keys have arrays for values, these arrays will merge recursively.
	 */
	public static function deepMerge()
	{
		switch(func_num_args())
		{
			case 0:
				return false;

			case 1:
				return func_get_arg(0);

			case 2:
				$args = func_get_args();
				$args[2] = [];

				if(is_array($args[0]) && is_array($args[1]))
				{
					foreach(array_unique(array_merge(array_keys($args[0]), array_keys($args[1]))) as $key)
					{
						$isKey0 = array_key_exists($key, $args[0]);
						$isKey1 = array_key_exists($key, $args[1]);

						if($isKey0 && $isKey1 && is_array($args[0][$key]) && is_array($args[1][$key]))
						{
							$args[2][$key] = self::deepMerge($args[0][$key], $args[1][$key]);
						}
						else if($isKey0 && $isKey1)
						{
							$args[2][$key] = $args[1][$key];
						}
						else if(!$isKey1)
						{
							$args[2][$key] = $args[0][$key];
						}
						else if(!$isKey0)
						{
							$args[2][$key] = $args[1][$key];
						}
					}

					return $args[2];
				}

				return $args[1];

			default:
				$selfCallable = [__CLASS__, __METHOD__];
				$args = func_get_args();
				$args[1] = $selfCallable($args[0], $args[1]);
				array_shift($args);

				return call_user_func_array($selfCallable, $args);

			break;
		}
	}
	
	/**
	 * Group values in pairs
	 * 
	 * name => value
	 *
	 * @return array
	 */
	public static function getPairs(array $elements): array
	{
		$paired = [];

		$count = count($elements);
		for($i = 0; $i < $count; $i+=2)
		{
			if($i % 2 === 0
				&& isset($elements[$i + 1])) // param pairs
			{
				$paired[$elements[$i]] = $elements[$i + 1];
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
	public static function flatten(array $array)
	{
		$flat = [];
		foreach($array as $key => $value)
		{
			if(is_array($value))
			{
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
			else
			{
				$flat[$key] = $value;
			}
		}

		return $flat;
	}
}
