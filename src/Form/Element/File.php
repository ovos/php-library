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
	protected array $types;
	
	public function __construct(
		array $types = [],
	)
	{
		$this->setTypes($types);
		$this->addValidator(new FileUploaded);
	}
	
	public function setTypes(
		array $types,
	): static
	{
		$this->types = $types;
		
		return $this;
	}
	
	public function getTypes(): array
	{
		return $this->types;
	}
	
	public function __get(
		string $key,
	): mixed
	{
		if(isset($_FILES[$this->id][$key]) === false)
		{
			return null;
		}
		
		return $_FILES[$this->id][$key];
	}
	
	public function __set(
		string $key,
		mixed $value,
	): void
	{
		$_FILES[$this->id][$key] = $value;
	}
	
	public function __isset(
		string $key,
	): bool
	{
		return array_key_exists($key, $_FILES[$this->id]);
	}
	
	public function toArrayObject(): ArrayObject
	{
		return new ArrayObject($_FILES[$this->id]);
	}
}
