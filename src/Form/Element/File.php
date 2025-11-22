<?php
declare(strict_types=1);

namespace Ovos\Form\Element;

use Ovos\ArrayObject;
use Ovos\Form\Element;
use Ovos\Form\Validator\FileUploaded;

use function array_key_exists;

/**
 * File
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
class File extends Element
{
	/**
	 * @var array
	 */
	protected array $_types;
	
	/**
	 * @param array $types
	 */
	public function __construct(array $types = [])
	{
		$this->setTypes($types);
		$this->addValidator(new FileUploaded);
	}
	
	/**
	 * @param array $types
	 *
	 * @return self
	 */
	public function setTypes(array $types): self
	{
		$this->_types = $types;
		
		return $this;
	}
	
	/**
	 * @return array
	 */
	public function getTypes(): array
	{
		return $this->_types;
	}
	
	/**
	 * @param string $key
	 *
	 * @return mixed
	 */
	public function __get(string $key): mixed
	{
		if(isset($_FILES[$this->_id][$key]) === false)
		{
			return null;
		}
		
		return $_FILES[$this->_id][$key];
	}
	
	/**
	 * @param string $key
	 * @param mixed $value
	 */
	public function __set(string $key, mixed $value): void
	{
		$_FILES[$this->_id][$key] = $value;
	}
	
	/**
	 * @param string $key
	 *
	 * @return bool
	 */
	public function __isset(string $key): bool
	{
		return array_key_exists($key, $_FILES[$this->_id]);
	}
	
	/**
	 * @return ArrayObject
	 */
	public function toArrayObject(): ArrayObject
	{
		return new ArrayObject($_FILES[$this->_id]);
	}
}
