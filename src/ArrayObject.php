<?php
declare(strict_types=1);

namespace Ovos;

use ArrayObject as BaseArrayObject;

use function array_shift;
use function array_merge;
use function explode;
use function is_string;

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
	 * @param string|array $path
	 * @param ?self $arrayObject
	 * 
	 * @return mixed (self|mixed|null)
	 */
	public function get(string|array $path, ?self $arrayObject = null): mixed
	{
		$pathElements = is_string($path)
			? explode('.', $path) // break the path into parts
			: $path;
		
		return $this->_getFromPath($pathElements, 
			$arrayObject ?? $this);
	}
	
	/**
	 * @param array $pathElements
	 * @param ArrayObject $arrayObject
	 * 
	 * @return mixed
	 */
	protected function _getFromPath(array $pathElements,
		self $arrayObject,
	): mixed
	{
		$currentPath = array_shift($pathElements);
		
		if($arrayObject->offsetExists($currentPath))
		{
			$nextArrayObject = $arrayObject->offsetGet($currentPath);
			
			if(empty($pathElements)) // base case: no more elements
			{
				return $nextArrayObject;
			}
			
			// recursive case: continue with the remaining path elements
			if($nextArrayObject instanceof self)
			{
				return $this->_getFromPath($pathElements, $nextArrayObject);
			}
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
	 * @return ?array
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
		
		return explode($separator, $value);
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
