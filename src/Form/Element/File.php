<?php
declare(strict_types=1);

namespace Ovos\Form\Element;

use Ovos\ArrayObject;
use Ovos\Form\Element;
use Ovos\Form\Validator\FileUploaded;

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
	protected $_types;

	/**
	 * @param array $types
	 */
	public function __construct($types = [])
	{
		$this->setTypes($types);
		$this->addValidator(new FileUploaded);
	}

	/**
	 * @param array $types
	 * 
	 * @return $this
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
	public function __get(string $key)
	{
		if(!isset($_FILES[$this->_id][$key]))
		{
			return null;
		}

		return $_FILES[$this->_id][$key];
	}

	/**
	 * @param string $key
	 * @param mixed $value
	 * 
	 * @return $this
	 */
	public function __set(string $key, $value): self
	{
		$_FILES[$this->_id][$key] = $value;
		
		return $this;
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
