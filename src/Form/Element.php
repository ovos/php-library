<?php
declare(strict_types=1);

namespace Ovos\Form;

use Ovos\Exception;
use Ovos\Form;

use function count;
use function is_array;

/**
 * Element
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
class Element
{
	/**
	 * @var string
	 */
	protected string $_id;
	
	/**
	 * @var ?Form
	 */
	protected ?Form $_form = null;
	
	/**
	 * @var null|string|bool|int|float|array
	 */
	protected null|string|bool|int|float|array $_value = null;
	
	/**
	 * @var ?string
	 */
	protected ?string $_label = null;
	
	/**
	 * @var Validator[]
	 */
	protected array $_validators = [];
	
	/**
	 * @var Filter[]
	 */
	protected array $_filters = [];
	
	/**
	 * @var Error[]
	 */
	protected array $_errors = [];
	
	/**
	 * @param string $id
	 * 
	 * @return self
	 */
	public function setId(string $id): self
	{
		$this->_id = $id;
		
		return $this;
	}
	
	/**
	 * @param bool $withFormId
	 * 
	 * @return string
	 */
	public function getId(bool $withFormId = true): string
	{
		$id = $this->_id;
		if($withFormId
			&& $formId = $this->_form->getId())
		{
			$id = $formId . '_' . $id;
		}
		
		return $id;
	}
	
	/**
	 * @param bool $withFormId
	 * 
	 * @return string
	 */
	public function getName(bool $withFormId = true): string
	{
		$name = $this->_id;
		if($withFormId
			&& $formId = $this->_form->getId())
		{
			$name = $formId . '[' . $name . ']';
		}
		
		return $name;
	}
	
	/**
	 * @param ?Form $form
	 *
	 * @return self
	 */
	public function setForm(?Form $form): self
	{
		$this->_form = $form;
		
		return $this;
	}
	
	/**
	 * @return ?Form
	 */
	public function getForm(): ?Form
	{
		if($this->_form === null)
		{
			throw new Exception('The element is not yet assigned to a form.');
		}
		
		return $this->_form;
	}
	
	/**
	 * @param null|string|bool|int|float|array $value
	 *
	 * @return self
	 */
	public function setValue(null|string|bool|int|float|array $value): self
	{
		$this->reset(); // clear cache of getValue()
		$this->getForm()
			->setValue($this->_id, $value);
		
		return $this;
	}
	
	/**
	 * @param bool $default
	 * 
	 * @return null|string|bool|int|float|array
	 */
	public function getValue(bool $default = false): null|string|bool|int|float|array
	{
		$value = $this->_form
			->getRawValue($this->_id);
		
		// return default value, if no other value is present
		// do not filter it, we assume it's in filtered state
		if($value === null
			&& $default === true)
		{
			return $this->_form
				->getDefaultValue($this->_id);
		}
		
		// if default value was not requested, process our value & cache it for future calls
		// some filters also process null values (for example casting to int)
		if($this->_value === null)
		{
			if(is_array($value))
			{
				foreach($value as &$item)
				{
					$item = $this->filterValue($item);
				}
				unset($item);
			}
			else
			{
				$value = $this->filterValue($value);
			}
			
			$this->_value = $value;
		}
		
		// return cached value
		return $this->_value;
	}
	
	/**
	 * @return null|string|bool|int|float|array
	 */
	public function getInputValue(): null|string|bool|int|float|array
	{
		return $this->getValue(true);
	}
	
	/**
	 * @return null|string|bool|int|float|array
	 */
	public function getUserValue(): null|string|bool|int|float|array
	{
		return $this->getValue(false);
	}
	
	/**
	 * @return self
	 */
	public function reset(): self
	{
		$this->_value = null;
		
		return $this;
	}
	
	/**
	 * @param ?string $label
	 * 
	 * @return self
	 */
	public function setLabel(?string $label): self
	{
		$this->_label = $label;
		
		return $this;
	}
	
	/**
	 * @return ?string
	 */
	public function getLabel(): ?string
	{
		return $this->_label;
	}
	
	/**
	 * @param null|string|bool|int|float|array $default
	 *
	 * @return self
	 */
	public function setDefault(null|string|bool|int|float|array $default): self
	{
		$this->getForm()
			->setDefault($this->_id, $default);
		
		return $this;
	}	
	
	/**
	 * @param mixed $value
	 * 
	 * @return mixed
	 */
	public function filterValue(mixed $value): mixed
	{
		foreach($this->_filters as $filter)
		{
			$value = $filter->filter($value);
		}
		
		return $value;
	}
	
	/**
	 * @param Filter $filter
	 *
	 * @return self
	 */
	public function addFilter(Filter $filter): self
	{
		$this->_filters[] = $filter;
		
		return $this;
	}
	
	/**
	 * @param Validator $validator
	 *
	 * @return self
	 */
	public function addValidator(Validator $validator): self
	{
		$this->_validators[] = $validator;
		
		return $this;
	}
	
	/**
	 * @return bool
	 */
	public function isValid(): bool
	{
		$this->_errors = []; // reset errors
		$value = $this->getUserValue();
		
		foreach($this->_validators as $validator)
		{
			$validator->setElement($this);
			if($validator->isValid($value) === false)
			{
				$this->addErrors($validator->getErrors());
			}
		}
		
		return $this->hasErrors() === false;
	}
	
	/**
	 * @param Error $error
	 *
	 * @return self
	 */
	public function addError(Error $error): self
	{
		$error->setElement($this);
		$this->_errors[] = $error;
		
		return $this;
	}
	
	/**
	 * @param Error[] $errors
	 *
	 * @return self
	 */
	public function addErrors(array $errors): self
	{
		foreach($errors as $error)
		{
			$this->addError($error);
		}
		
		return $this;
	}
	
	/**
	 * @return bool
	 */
	public function hasErrors(): bool
	{
		return count($this->_errors) > 0;
	}
	
	/**
	 * @return self
	 */
	public function clearErrors(): self
	{
		$this->_errors = [];
		
		return $this;
	}
	
	/**
	 * @return Error[]
	 */
	public function getErrors(): array
	{
		return $this->_errors;
	}
	
	/**
	 * @return string
	 */
	public function __toString(): string
	{
		return (string)$this->getValue();
	}
	
	/**
	 * @return array
	 */
	public function __debugInfo(): array
	{
		return [
			$this->getValue()
		];
	}
}
