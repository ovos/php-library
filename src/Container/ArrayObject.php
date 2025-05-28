<?php
declare(strict_types=1);

namespace Ovos\Container;

use Attribute;

/**
 * ArrayObject
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
class ArrayObject implements Injected
{
	/**
	 * @var array 
	 */
	protected array $_path;
	
	/**
	* @param array $path
	*/
	public function __construct(...$path)
	{
		$this->_path = $path;
	}
	
	/**
	* @return array
	*/
	public function getPath(): array
	{
		return $this->_path;
	}
	
	public function process(object $object): mixed
	{
		return $object->getPath($this->_path);
	}
}
