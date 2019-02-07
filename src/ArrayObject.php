<?php
declare(strict_types=1);

namespace Ovos;

use ArrayObject as BaseArrayObject;
use IteratorAggregate;

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
	public function __construct($array = [],
		$flags = self::ARRAY_AS_PROPS,
		$iteratorClass = 'ArrayIterator')
	{
		parent::__construct($array, $flags, $iteratorClass);
	}

	/**
	 * @param string $offset
	 * 
	 * @return null|mixed
	 */
	public function offsetGet($offset)
	{
		if(parent::offsetExists($offset) === false)
		{
			return null;
		}
		
		return parent::offsetGet($offset);
	}

	/**
	 * Returns config value specified by dot separated path
	 *
	 * @param string $path
	 * @param self $config
	 *
	 * @return self|null
	 *
	 * @throws Exception
	 */
	public function get(string $path, self $config = null): ?self
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

			if(\count($pathElements))
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
	 * @return $this
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
