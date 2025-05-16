<?php
declare(strict_types=1);

namespace Ovos;

use ArrayObject as BaseArrayObject;

use function count;
use function array_shift;
use function array_merge;
use function implode;
use function explode;

/**
 * ArrayObject
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
class ArrayObject extends BaseArrayObject
{
	/**
	 * @param array $array
	 * @param int $flags
	 * @param string $iteratorClass
	 */
	public function __construct(array $array = [],
		int $flags = self::ARRAY_AS_PROPS,
		string $iteratorClass = 'ArrayIterator')
	{
		parent::__construct($array, $flags, $iteratorClass);
	}
	
	/**
	 * @param mixed $key
	 * 
	 * @return mixed
	 */
	public function offsetGet(mixed $key): mixed
	{
		if($this->offsetExists($key) === false)
		{
			return null;
		}
		
		return parent::offsetGet($key);
	}
	
	/**
	 * Returns a nested value specified by a dot-separated path
	 *
	 * @param string $path
	 * @param ?self $config
	 *
	 * @return mixed (self|mixed|null)
	 *
	 * @throws Exception
	 */
	public function get(string $path, ?self $config = null): mixed
	{
		$pathElements = explode('.', $path);
		$currentPath = array_shift($pathElements);
		
		if($config === null)
		{
			$config = $this;
		}
		
		if($config->offsetExists($currentPath))
		{
			$config = $config->offsetGet($currentPath);
			
			if(count($pathElements))
			{
				$path = implode('.', $pathElements);
				return $this->get($path, $config);
			}
			
			return $config;
		}
		
		return null;
	}
	
	/**
	 * @param array $toMerge
	 * 
	 * @return self
	 */
	public function merge(array $toMerge): self
	{
		$this->exchangeArray(array_merge($this->getArrayCopy(), $toMerge));
		
		return $this;
	}
	
	/**
	 * @param string $key
	 * @param string $separator
	 *
	 * @return array|null
	 */
	public function asArray(string $key, string $separator = ', '): ?array
	{
		if($this->offsetExists($key) === false)
		{
			return null;
		}
		
		// return null on non-existing properties
		if(($value = $this->offsetGet($key)) === null)
		{
			return null;
		}
		
		return explode(', ', $value);
	}
	
	/**
	 * @param array $array
	 *
	 * @return self
	 */
	public static function factory(array $array = []): self
	{
		return new self($array, self::ARRAY_AS_PROPS);
	}
}
