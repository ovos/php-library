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
 * @author Marcin Gil <mg@ovos.at>
 */
class ArrayObject extends BaseArrayObject
{
	public function __construct(
		array $array = [],
		int $flags = self::ARRAY_AS_PROPS,
		string $iteratorClass = 'ArrayIterator',
	)
	{
		parent::__construct($array, $flags, $iteratorClass);
	}
	
	public function get(
		mixed $key,
	): mixed
	{
		return $this->offsetGet($key);
	}
	
	public function getArray(
		mixed $key = null,
	): array
	{
		if($key === null)
		{
			return $this->getArrayCopy();
		}
		
		if($this->offsetExists($key) === false)
		{
			return [];
		}
		
		return $this->offsetGet($key)
			->getArrayCopy();
	}
	
	public function offsetGet(
		mixed $key,
	): mixed
	{
		if($this->offsetExists($key) === false)
		{
			return null;
		}
		
		// convert an array to ArrayObject
		// so that it will be referenced and using [] will work
		$value = parent::offsetGet($key);
		if(is_array($value))
		{
			$value = new static($value);
			$this->offsetSet($key, $value);
		}
		
		return $value;
	}
	
	public function offsetSet(
		mixed $key,
		mixed $value,
	): void
	{
		if(is_array($value))
		{
			// convert an array to ArrayObject
			$value = new static($value);
		}
		
		parent::offsetSet($key, $value);
	}
	
	public function __get(
		string $key,
	): mixed
	{
		return $this->offsetExists($key)
			? $this->offsetGet($key)
			: null;
	}
	
	public function __set(
		string $key,
		mixed $value,
	): void
	{
		$this->offsetSet($key, $value);
	}
	
	public function __isset(
		string $name,
	): bool
	{
		return $this->offsetExists($name);
	}
	
	/**
	 * Returns a nested value specified by a dot-separated path
	 */
	public function getPath(
		string|array $path,
		?self $arrayObject = null,
	): mixed
	{
		$pathElements = is_string($path)
			? explode('.', $path) // break the path into parts
			: $path;
		
		return $this->getFromPath($pathElements, 
			$arrayObject ?? $this);
	}
	
	protected function getFromPath(array $pathElements,
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
				return $this->getFromPath($pathElements, $nextArrayObject);
			}
		}
		
		return null;
	}
	
	public function merge(
		array $toMerge,
	): static
	{
		$this->exchangeArray(array_merge($this->getArrayCopy(), $toMerge));
		
		return $this;
	}
	
	/**
	 * Returns value as an array
	 */
	public function asArray(
		string $key,
		string $separator = ', ',
	): ?array
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
	
	public static function factory(
		array $array = [],
	): static
	{
		return new static($array, static::ARRAY_AS_PROPS);
	}
}
